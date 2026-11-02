<?php

namespace App\Domain\Migration;

use App\Domain\Accounting\LedgerService;
use App\Domain\Banking\MoneyAccountService;
use App\Domain\Inventory\OpeningStockService;
use App\Domain\Parties\PartyService;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Go-live data migration (backlog P17.1-BE-05; BR-028; 10 §4): opening balances of customers, suppliers, ledgers and
 * money accounts, and opening stock of existing products, from a CSV laid out like docs/import-templates/<type>.csv.
 *
 * Every row goes through the same domain services as the screens (PartyService, LedgerService, MoneyAccountService,
 * OpeningStockService), so the opening entries are dated the financial year's start against the opening balance
 * adjustment ledger exactly as BR-028 says. The whole file runs in one transaction with a savepoint per row: a dry
 * run (and any run with an error) rolls everything back and only reports; a real run commits only when every row
 * passed. Rows already in the books (same code / name / product and warehouse) are refused, never doubled.
 */
final class OpeningImportService
{
    public const TYPES = ['customers', 'suppliers', 'ledgers', 'banks', 'stock'];

    public const MAX_ROWS = 20000;

    /** Columns per type (header row; any order; case-insensitive). Required ones are checked per row. */
    public const COLUMNS = [
        'customers' => ['name', 'mobile', 'email', 'address', 'district', 'contact_person', 'credit_limit', 'opening_balance', 'opening_side'],
        'suppliers' => ['name', 'mobile', 'email', 'address', 'district', 'contact_person', 'opening_balance', 'opening_side'],
        'ledgers' => ['code', 'name', 'group', 'opening_balance', 'opening_side'],
        'banks' => ['account_type', 'name', 'bank_name', 'branch_name', 'account_name', 'account_number', 'opening_balance'],
        'stock' => ['product_code', 'warehouse_code', 'quantity', 'unit_cost'],
    ];

    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const COST = '/^\d{1,14}(\.\d{1,4})?$/';

    public function __construct(private readonly PartyService $parties, private readonly LedgerService $ledgers, private readonly MoneyAccountService $accounts,
        private readonly OpeningStockService $stock) {}

    /** @return array{type: string, dry_run: bool, rows: int, imported: int, would_import: int, errors: list<array{row: int, column: ?string, message: string}>} */
    public function import(string $type, string $path, bool $dryRun): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Type must be one of '.implode(', ', self::TYPES));
        }
        $rows = $this->read($path, $type);
        $errors = [];
        $imported = 0;
        $seen = [];
        try {
            DB::transaction(function () use ($type, $rows, &$errors, &$imported, &$seen, $dryRun) {
                foreach ($rows as $no => $row) {
                    $rowErrors = $this->check($type, $row, $seen, $no);
                    if ($rowErrors) {
                        array_push($errors, ...array_map(fn ($e) => ['row' => $no] + $e, $rowErrors));

                        continue;
                    }
                    try {
                        DB::transaction(fn () => $this->apply($type, $row)); // savepoint: a failing row leaves the others intact
                        $imported++;
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $field => $messages) {
                            $errors[] = ['row' => $no, 'column' => $field, 'message' => $messages[0]];
                        }
                    } catch (QueryException $e) {
                        $errors[] = ['row' => $no, 'column' => null, 'message' => 'Could not save this row: '.($e->errorInfo[2] ?? $e->getMessage())];
                    } catch (ApiProblem $e) {
                        $fields = $e->errors;
                        $errors[] = ['row' => $no, 'column' => $fields ? (string) array_key_first($fields) : null,
                            'message' => $fields ? (string) (reset($fields)[0] ?? $e->getMessage()) : ($e->detail ?? $e->getMessage())];
                    }
                }
                if ($dryRun || $errors) {
                    throw new RollBack;
                }
            });
        } catch (RollBack) {
            // nothing kept
        }

        return ['type' => $type, 'dry_run' => $dryRun, 'rows' => count($rows), 'imported' => ($dryRun || $errors) ? 0 : $imported, 'would_import' => $imported, 'errors' => $errors];
    }

    /** @return array<int, array<string, string>> row number (as in the file, header = 1) → values by column */
    private function read(string $path, string $type): array
    {
        $h = @fopen($path, 'r') ?: throw new RuntimeException("Cannot read {$path}");
        $header = fgetcsv($h) ?: throw new RuntimeException('The file is empty');
        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $header);
        $unknown = array_diff(array_filter($header), self::COLUMNS[$type]);
        if ($unknown) {
            throw new RuntimeException('Unknown columns for '.$type.': '.implode(', ', $unknown).'. Expected: '.implode(', ', self::COLUMNS[$type]));
        }
        $rows = [];
        $no = 1;
        while (($line = fgetcsv($h)) !== false) {
            $no++;
            if ($line === [null] || implode('', array_map('trim', array_map('strval', $line))) === '') {
                continue;
            }
            $rows[$no] = array_combine($header, array_map(fn ($v) => trim((string) $v), array_pad(array_slice($line, 0, count($header)), count($header), '')));
            if (count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('At most '.self::MAX_ROWS.' rows per file');
            }
        }
        fclose($h);

        return $rows;
    }

    /** Shape and duplicate checks before a row touches the books. @return list<array{column: ?string, message: string}> */
    private function check(string $type, array $r, array &$seen, int $no): array
    {
        $e = [];
        // Plain closures: they add to $e by reference (arrow functions would only change a copy).
        $need = function (string $c, string $label) use (&$e, $r) {
            if (($r[$c] ?? '') === '') {
                $e[] = ['column' => $c, 'message' => "Enter the {$label}"];
            }
        };
        $money = function (string $c) use (&$e, $r) {
            if (($r[$c] ?? '') !== '' && ! preg_match(self::MONEY, $r[$c])) {
                $e[] = ['column' => $c, 'message' => 'Use a positive amount with up to 2 decimals'];
            }
        };
        $side = function () use (&$e, $r) {
            if (($r['opening_side'] ?? '') !== '' && ! in_array(strtolower($r['opening_side']), ['dr', 'cr'], true)) {
                $e[] = ['column' => 'opening_side', 'message' => 'Use dr or cr'];
            }
        };
        switch ($type) {
            case 'customers':
            case 'suppliers':
                $need('name', 'name');
                $need('mobile', 'mobile number');
                $money('opening_balance');
                $money('credit_limit');
                $side();
                $key = $type.'|'.strtolower($r['name'] ?? '').'|'.($r['mobile'] ?? '');
                if (isset($seen[$key])) {
                    $e[] = ['column' => 'name', 'message' => "Same name and mobile as row {$seen[$key]}"];
                }
                $seen[$key] ??= $no;
                if (($r['name'] ?? '') !== '' && DB::table('parties')->where('company_id', app(CurrentCompany::class)->id())->where('party_type', rtrim($type, 's'))
                    ->where('name', $r['name'])->where('mobile', $r['mobile'] ?? '')->exists()) {
                    $e[] = ['column' => 'name', 'message' => 'Already in the books (same name and mobile)'];
                }
                break;
            case 'ledgers':
                $need('code', 'ledger code');
                $need('opening_balance', 'opening balance');
                $need('opening_side', 'side (dr or cr)');
                $money('opening_balance');
                $side();
                if (($r['code'] ?? '') !== '' && ! Ledger::where('code', $r['code'])->exists()) {
                    $need('name', 'name (a new ledger)');
                    $need('group', 'account group code or name (a new ledger)');
                }
                if (isset($seen['ledger|'.($r['code'] ?? '')])) {
                    $e[] = ['column' => 'code', 'message' => 'This ledger is already in an earlier row'];
                }
                $seen['ledger|'.($r['code'] ?? '')] = $no;
                break;
            case 'banks':
                $need('account_type', 'account type (bank, cash, wallet, card or cash_credit)');
                $money('opening_balance');
                if (($r['account_type'] ?? '') === 'bank') {
                    $need('bank_name', 'bank name');
                    $need('account_number', 'account number');
                }
                break;
            case 'stock':
                $need('product_code', 'product code');
                $need('warehouse_code', 'warehouse code');
                foreach (['quantity' => self::QTY, 'unit_cost' => self::COST] as $c => $re) {
                    $need($c, str_replace('_', ' ', $c));
                    if (($r[$c] ?? '') !== '' && ! preg_match($re, $r[$c])) {
                        $e[] = ['column' => $c, 'message' => $c === 'quantity' ? 'Use a quantity with up to 3 decimals' : 'Use a cost with up to 4 decimals'];
                    }
                }
                $key = 'stock|'.($r['product_code'] ?? '').'|'.($r['warehouse_code'] ?? '');
                if (isset($seen[$key])) {
                    $e[] = ['column' => 'product_code', 'message' => 'This product and warehouse are already in an earlier row'];
                }
                $seen[$key] = $no;
                break;
        }

        return $e;
    }

    private function apply(string $type, array $r): void
    {
        $opt = fn (string $c) => ($r[$c] ?? '') === '' ? null : $r[$c];
        switch ($type) {
            case 'customers':
            case 'suppliers':
                $in = ['party_type' => rtrim($type, 's'), 'name' => $r['name'], 'mobile' => $r['mobile'], 'default_currency_code' => 'BDT', 'country_code' => 'BD', 'email' => $opt('email'), 'address' => $opt('address'),
                    'district' => $opt('district'), 'contact_person' => $opt('contact_person')];
                if ($type === 'customers' && $opt('credit_limit') !== null) {
                    $in['credit_limit'] = $r['credit_limit'];
                }
                if ($opt('opening_balance') !== null) {
                    $in['opening_balance'] = $r['opening_balance'];
                    $in['opening_side'] = strtolower($opt('opening_side') ?? ($type === 'customers' ? 'dr' : 'cr'));
                }
                $this->parties->create($in);
                break;
            case 'ledgers':
                $opening = ['opening_balance' => $r['opening_balance'], 'opening_side' => strtolower($r['opening_side'])];
                $ledger = Ledger::where('code', $r['code'])->first();
                if ($ledger) {
                    $this->ledgers->update($ledger, $opening, (int) $ledger->lock_version);
                } else {
                    $group = AccountGroup::where('code', $r['group'])->orWhere('name', $r['group'])->first()
                        ?? throw new ApiProblem('validation_failed', errors: ['group' => ["No account group {$r['group']}"]]);
                    $this->ledgers->create(['name' => $r['name'], 'code' => $r['code'], 'account_group_id' => $group->id] + $opening);
                }
                break;
            case 'banks':
                $this->accounts->create(array_filter(['account_type' => $r['account_type'], 'name' => $opt('name'), 'bank_name' => $opt('bank_name'),
                    'branch_name' => $opt('branch_name'), 'account_name' => $opt('account_name'), 'account_number' => $opt('account_number'),
                    'opening_balance' => $opt('opening_balance')], fn ($v) => $v !== null));
                break;
            case 'stock':
                $product = Product::where('code', $r['product_code'])->first()
                    ?? throw new ApiProblem('validation_failed', errors: ['product_code' => ["No product {$r['product_code']}"]]);
                $warehouse = Warehouse::where('code', $r['warehouse_code'])->first()
                    ?? throw new ApiProblem('validation_failed', errors: ['warehouse_code' => ["No warehouse {$r['warehouse_code']}"]]);
                if (! $product->track_stock) {
                    throw new ApiProblem('validation_failed', errors: ['product_code' => ["{$product->code} is a service; it has no stock"]]);
                }
                if (DB::table('stock_movements')->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->exists()) {
                    throw new ApiProblem('validation_failed', errors: ['product_code' => ["{$product->code} already has stock movements in {$warehouse->code}; opening stock is locked (BR-028)"]]);
                }
                $this->stock->post($product, $warehouse, Quantity::of($r['quantity']), UnitCost::of($r['unit_cost']));
                break;
        }
    }
}
