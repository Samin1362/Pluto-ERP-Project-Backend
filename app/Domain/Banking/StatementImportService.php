<?php

namespace App\Domain\Banking;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\MoneyAccount;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * POST /bank-statement-imports (G5, G10; CF-19; TC-BNK-003; backlog P12.1-BE-04).
 *
 * Reads a CSV or XLSX bank statement: a header row, then one transaction per row. Columns are found by their usual
 * names (date, description/particulars, reference/cheque no., and either one signed amount or separate
 * withdrawal/debit and deposit/credit columns), or named in `mapping` ({"date": "Txn Date", "debit": "Withdrawal", …}).
 * Dates in Y-m-d, d/m/Y, d-m-Y, d-M-Y or d M Y. Amounts are signed: + money in, − money out.
 *
 * The file's SHA-256 is kept (unique per account), so the same file twice is refused (409 duplicate_statement); a
 * line already imported for the account (same date, amount and reference or description) is skipped and counted.
 * Parsing runs in the request (statements are small; the queue can take it over later).
 */
final class StatementImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const NAMES = [
        'date' => ['date', 'txn_date', 'transaction_date', 'value_date', 'trans_date', 'posting_date'],
        'description' => ['description', 'particulars', 'narration', 'details', 'remarks', 'transaction_details'],
        'reference' => ['reference', 'ref', 'ref_no', 'reference_no', 'cheque_no', 'chq_no', 'cheque_number', 'instrument_no', 'trx_id', 'transaction_id'],
        'amount' => ['amount', 'signed_amount'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'dr', 'paid_out', 'money_out'],
        'credit' => ['credit', 'deposit', 'deposits', 'cr', 'paid_in', 'money_in'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{id: int, line_count: int, period_from: string, period_to: string, duplicates_skipped: int} */
    public function import(UploadedFile $file, int $accountId, ?array $mapping): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiProblem('file_too_large', 'The statement is larger than 5 MB.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new ApiProblem('file_type_not_allowed', 'Upload the bank statement as CSV or XLSX.');
        }
        $account = MoneyAccount::findOrFail($accountId);
        if (! in_array($account->account_type, ['bank', 'cash_credit', 'wallet', 'card'], true)) {
            throw new ApiProblem('validation_failed', errors: ['money_account_id' => ["{$account->name} is a cash account; count it instead of importing a statement"]]);
        }
        $content = (string) file_get_contents($file->getRealPath());
        $hash = hash('sha256', $content);
        $path = "statements/{$account->company_id}/{$account->id}/{$hash}.{$ext}";
        if ($dup = BankStatementImport::where('money_account_id', $account->id)->where('file_sha256', $hash)->first()) {
            throw new ApiProblem('duplicate_statement', "This statement was already imported on {$dup->created_at->format('j M Y')} ({$dup->original_name}).", extra: ['import_id' => $dup->id]);
        }
        $rows = $ext === 'xlsx' ? $this->readXlsx($file->getRealPath()) : $this->readCsv($content);
        $lines = $this->parse($rows, $mapping ?? []);
        if (! $lines) {
            throw new ApiProblem('validation_failed', 'No transactions were found in the file.', errors: ['file' => ['The file has a header row but no transactions']]);
        }

        return DB::transaction(function () use ($account, $file, $path, $hash, $content, $lines) {
            Storage::disk('local')->put($path, $content);
            $import = BankStatementImport::create(['money_account_id' => $account->id, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_path' => $path, 'file_sha256' => $hash,
                'period_from' => min(array_column($lines, 'date')), 'period_to' => max(array_column($lines, 'date')), 'line_count' => 0, 'status' => 'to_match']);
            $existing = BankStatementLine::where('money_account_id', $account->id)->get(['txn_date', 'amount', 'bank_reference', 'description'])
                ->map(fn ($l) => $this->key($l->txn_date->toDateString(), (string) $l->amount, $l->bank_reference, $l->description))->flip();
            $n = 0;
            $skipped = 0;
            foreach ($lines as $l) {
                $key = $this->key($l['date'], $l['amount'], $l['reference'], $l['description']);
                if (isset($existing[$key])) {
                    $skipped++;

                    continue;
                }
                $existing[$key] = true;
                BankStatementLine::create(['bank_statement_import_id' => $import->id, 'money_account_id' => $account->id, 'line_no' => ++$n, 'txn_date' => $l['date'],
                    'description' => mb_substr($l['description'] ?: '—', 0, 255), 'bank_reference' => $l['reference'] ? mb_substr($l['reference'], 0, 80) : null,
                    'amount' => $l['amount'], 'status' => 'unmatched']);
            }
            $import->forceFill(['line_count' => $n])->save();
            $this->audit->record('created', $import, null, ['account' => $account->name, 'file' => $import->original_name, 'lines' => $n, 'skipped' => $skipped]);

            return ['id' => $import->id, 'line_count' => $n, 'period_from' => $import->period_from->toDateString(), 'period_to' => $import->period_to->toDateString(),
                'duplicates_skipped' => $skipped];
        });
    }

    /** @return list<array{date: string, description: string, reference: ?string, amount: string}> */
    private function parse(array $rows, array $mapping): array
    {
        $norm = fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $h), '_'));
        $head = array_map($norm, $rows[0] ?? []);
        $col = [];
        foreach (self::NAMES as $field => $names) {
            $want = isset($mapping[$field]) ? [$norm($mapping[$field])] : $names;
            foreach ($want as $name) {
                $i = array_search($name, $head, true);
                if ($i !== false) {
                    $col[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($col['date']) || (! isset($col['amount']) && ! isset($col['debit']) && ! isset($col['credit']))) {
            throw new ApiProblem('validation_failed', 'The statement columns were not recognised.',
                errors: ['mapping' => ['Name the date column and the amount column (or the withdrawal and deposit columns) in the mapping']]);
        }
        $out = [];
        $errors = [];
        foreach (array_slice($rows, 1, null, true) as $i => $r) {
            $get = fn (string $f) => isset($col[$f]) ? trim((string) ($r[$col[$f]] ?? '')) : '';
            if (implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue;
            }
            $date = $this->date($get('date'));
            $amount = $this->amount($get('amount'), $get('debit'), $get('credit'));
            if (! $date || $amount === null) {
                if ($get('date') === '' && $get('amount').$get('debit').$get('credit') === '') {
                    continue; // a total or blank row
                }
                $errors[] = 'Row '.($i + 1).': '.(! $date ? "\"{$get('date')}\" is not a date" : 'no amount');

                continue;
            }
            $out[] = ['date' => $date, 'description' => $get('description'), 'reference' => $get('reference') ?: null, 'amount' => $amount];
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', 'Some rows of the statement could not be read.', errors: ['file' => array_slice($errors, 0, 20)]);
        }

        return $out;
    }

    private function date(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd-M-Y', 'd M Y', 'd.m.Y', 'd/m/y', 'Y/m/d'] as $f) {
            try {
                $d = CarbonImmutable::createFromFormat("!{$f}", $v, 'Asia/Dhaka');
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($f) === $v) {
                return $d->toDateString();
            }
        }

        return null;
    }

    /** Signed amount as a decimal string: the amount column, or credit − debit. */
    private function amount(string $amount, string $debit, string $credit): ?string
    {
        $clean = fn (string $v) => str_replace([',', '৳', 'Tk', ' '], '', $v);
        $ok = fn (string $v) => $v !== '' && preg_match('/^-?\d+(\.\d{1,2})?$/', $v);
        $amount = $clean($amount);
        if ($ok($amount)) {
            return (string) Money::of($amount);
        }
        [$dr, $cr] = [$clean($debit), $clean($credit)];
        if (! $ok($dr) && ! $ok($cr)) {
            return null;
        }

        return (string) Money::of($ok($cr) ? $cr : '0')->minus($ok($dr) ? $dr : '0');
    }

    private function key(string $date, string $amount, ?string $reference, ?string $description): string
    {
        return $date.'|'.Money::of($amount).'|'.($reference ?: mb_strtolower(trim((string) $description)));
    }

    /** @return list<list<string>> */
    private function readCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (trim($line) !== '') {
                $out[] = str_getcsv($line, ',', '"', '');
            }
        }

        return $out;
    }

    /** @return list<list<string>> the first sheet */
    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[] = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $row->toArray());
            }
            break;
        }
        $reader->close();

        return $out;
    }
}
