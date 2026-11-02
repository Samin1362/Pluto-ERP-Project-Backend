<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Volume data for the performance tests (backlog P17.1-BE-02; TC-NFR-001/002): balanced, posted two-line journal
 * vouchers spread over the current financial year of one company, bulk-inserted. Only runs on a disposable database
 * (pluto_e2e or a *_perf copy) — never on dev or live data. Posted journals cannot be deleted afterwards (BR-003
 * triggers): reset the database instead (./scripts/e2e-prepare.sh).
 */
class PerfSeed extends Command
{
    protected $signature = 'pluto:perf:seed {--lines=1000000 : journal lines to add (two per voucher)} {--company= : company id (default: the first)} {--chunk=2000 : vouchers per insert}';

    protected $description = 'Bulk-load posted journal vouchers into a disposable database for k6 performance tests';

    public function handle(): int
    {
        $db = (string) DB::connection()->getDatabaseName();
        if ($db !== 'pluto_e2e' && ! str_ends_with($db, '_perf')) {
            $this->error("Refusing to load test volume into '{$db}': use pluto_e2e or a *_perf copy.");

            return self::FAILURE;
        }
        $companyId = (int) ($this->option('company') ?: DB::table('companies')->min('id'));
        if (! $companyId) {
            $this->error('No company: create one first.');

            return self::FAILURE;
        }
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $periods = DB::table('accounting_periods as p')->join('fiscal_years as f', 'f.id', '=', 'p.fiscal_year_id')->where('p.company_id', $companyId)
            ->where('p.start_date', '<=', $today->toDateString())->orderBy('p.start_date')->get(['p.id', 'p.fiscal_year_id', 'p.start_date', 'p.end_date']);
        $ledgers = DB::table('ledgers')->where('company_id', $companyId)->where('is_active', 1)->pluck('id')->all();
        if ($periods->isEmpty() || count($ledgers) < 2) {
            $this->error('The company needs an open financial year and at least two ledgers.');

            return self::FAILURE;
        }
        $user = DB::table('company_users')->where('company_id', $companyId)->min('user_id');
        $vouchers = intdiv(max(2, (int) $this->option('lines')), 2);
        $chunk = max(100, (int) $this->option('chunk'));
        $first = $periods->first()->start_date;
        $days = max(1, (int) CarbonImmutable::parse($first)->diffInDays($today) + 1);
        $nextEntry = (int) DB::table('journal_entries')->max('id') + 1;
        $nextLine = (int) DB::table('journal_lines')->max('id') + 1;
        $seq = (int) DB::table('journal_entries')->where('company_id', $companyId)->where('voucher_number', 'like', 'PERF-%')->count();
        $now = now()->toDateTimeString();
        mt_srand(17);

        DB::disableQueryLog();
        $bar = $this->output->createProgressBar($vouchers);
        for ($done = 0; $done < $vouchers; $done += $chunk) {
            $entries = $lines = [];
            for ($i = 0; $i < min($chunk, $vouchers - $done); $i++) {
                $date = CarbonImmutable::parse($first)->addDays(mt_rand(0, $days - 1))->toDateString();
                $p = $periods->last(fn ($x) => $x->start_date <= $date);
                $amount = number_format(mt_rand(100, 5000000) / 100, 2, '.', '');
                $dr = $ledgers[mt_rand(0, count($ledgers) - 1)];
                do {
                    $cr = $ledgers[mt_rand(0, count($ledgers) - 1)];
                } while ($cr === $dr);
                $seq++;
                $entries[] = ['id' => $nextEntry, 'company_id' => $companyId, 'fiscal_year_id' => $p->fiscal_year_id, 'accounting_period_id' => $p->id,
                    'entry_type' => 'journal', 'voucher_number' => sprintf('PERF-%07d', $seq), 'entry_date' => $date, 'narration' => 'Performance test volume',
                    'status' => 'posted', 'total_debit' => $amount, 'total_credit' => $amount, 'posted_at' => $now, 'posted_by' => $user, 'created_by' => $user,
                    'created_at' => $now, 'updated_at' => $now];
                foreach ([[1, $dr, $amount, '0.00'], [2, $cr, '0.00', $amount]] as [$no, $ledger, $debit, $credit]) {
                    $lines[] = ['id' => $nextLine++, 'company_id' => $companyId, 'journal_entry_id' => $nextEntry, 'line_no' => $no, 'ledger_id' => $ledger,
                        'debit' => $debit, 'credit' => $credit, 'entry_date' => $date, 'is_posted' => 1, 'created_at' => $now, 'updated_at' => $now];
                }
                $nextEntry++;
            }
            DB::transaction(function () use ($entries, $lines) {
                DB::table('journal_entries')->insert($entries);
                foreach (array_chunk($lines, 2000) as $part) {
                    DB::table('journal_lines')->insert($part);
                }
            });
            $bar->advance(count($entries));
        }
        $bar->finish();
        $this->newLine();
        $total = DB::table('journal_lines')->where('company_id', $companyId)->count();
        $this->info("Added {$vouchers} vouchers; company {$companyId} now has {$total} journal lines.");

        return self::SUCCESS;
    }
}
