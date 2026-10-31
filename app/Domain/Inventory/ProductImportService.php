<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockGroup;
use App\Models\TaxRule;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * POST /products/import (D1 Import; C-05; 06 §11.2; BR-026, BR-028, BR-036; backlog P11.1-BE-05).
 *
 * Reads a CSV or XLSX laid out like the import template (header row; columns below, in any order) and checks every
 * row: required name, unit and selling price; references by name or code (category, unit, stock group, tax rule,
 * preferred supplier, warehouse) must exist and be active; codes and barcodes unique in the file and the company. A dry
 * run only reports. A real run creates nothing unless every row is valid, then creates all products in one
 * transaction (codes from the category's series when blank). A row with opening stock receives it into the warehouse
 * as an `opening` movement dated the financial year's start, journalled Dr warehouse inventory / Cr opening balance
 * adjustment (3301) as an opening_balance entry (BR-028).
 */
final class ProductImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    /** Template columns and the header spellings accepted for each. */
    public const COLUMNS = [
        'name' => ['name', 'product_name', 'item'], 'item_type' => ['type', 'item_type'], 'code' => ['code', 'product_code', 'sku'],
        'category' => ['category'], 'unit' => ['unit'], 'barcode' => ['barcode'], 'selling_price' => ['selling_price', 'price', 'sale_price'],
        'wholesale_price' => ['wholesale_price'], 'purchase_cost' => ['purchase_cost', 'last_purchase_cost', 'cost'], 'tax_rule' => ['tax_rule', 'tax'],
        'stock_group' => ['stock_group', 'group'], 'preferred_supplier' => ['preferred_supplier', 'supplier'], 'income_ledger' => ['income_ledger'],
        'warehouse' => ['warehouse', 'warehouse_code'], 'opening_qty' => ['opening_qty', 'opening_stock'], 'opening_cost' => ['opening_cost', 'opening_unit_cost'],
        'reorder_level' => ['reorder_level'], 'reorder_qty' => ['reorder_qty'],
    ];

    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    private const COST = '/^\d{1,14}(\.\d{1,4})?$/';

    public function __construct(private readonly ProductService $products, private readonly OpeningStockService $openings, private readonly AccountMapper $mapper) {}

    /** @return array{dry_run: bool, rows: int, valid_rows: int, created_ids: list<int>, errors: list<array{row: int, column: ?string, code: string, message: string}>} */
    public function import(UploadedFile $file, bool $dryRun): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiProblem('file_too_large', 'The import file is larger than 10 MB.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new ApiProblem('file_type_not_allowed', 'Upload the product list as CSV or XLSX, laid out like the import template.');
        }
        $table = $ext === 'xlsx' ? $this->readXlsx($file->getRealPath()) : $this->readCsv((string) file_get_contents($file->getRealPath()));
        [$columns, $data] = $this->columns($table);
        if (count($data) > self::MAX_ROWS) {
            throw new ApiProblem('validation_failed', 'The file has more than '.self::MAX_ROWS.' rows; split it.', errors: ['file' => ['At most '.self::MAX_ROWS.' rows per import']]);
        }

        $errors = [];
        $prepared = [];
        $lookups = $this->lookups();
        $codes = [];
        $barcodes = [];
        foreach ($data as $n => $raw) {
            $row = [];
            foreach ($columns as $field => $index) {
                $row[$field] = trim((string) ($raw[$index] ?? ''));
            }
            if (implode('', $row) === '') {
                continue;
            }
            [$input, $opening, $rowErrors] = $this->row($n, $row, $lookups, $codes, $barcodes);
            array_push($errors, ...$rowErrors);
            if (! $rowErrors) {
                $prepared[] = [$input, $opening];
            }
        }
        $rows = count($prepared) + count(array_unique(array_column($errors, 'row')));
        $result = ['dry_run' => $dryRun, 'rows' => $rows, 'valid_rows' => count($prepared), 'created_ids' => [], 'errors' => $errors];
        if ($dryRun || $errors || ! $prepared) {
            return $result;
        }

        $result['created_ids'] = DB::transaction(function () use ($prepared) {
            $ids = [];
            foreach ($prepared as [$input, $opening]) {
                $product = $this->products->create($input);
                if ($opening) {
                    $this->openings->post($product, Warehouse::findOrFail($opening[0]), $opening[1], $opening[2]);
                }
                $ids[] = $product->id;
            }

            return $ids;
        });

        return $result;
    }

    /** Header row → field => column index. Name, unit and selling price columns are required. */
    private function columns(array $table): array
    {
        $head = array_map(fn ($h) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h))), $table[0] ?? []);
        $map = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $i = array_search($name, $head, true);
                if ($i !== false) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        $missing = array_diff(['name', 'unit', 'selling_price'], array_keys($map));
        if ($missing) {
            throw new ApiProblem('validation_failed', 'The file does not follow the import template.',
                errors: ['file' => ['Missing column(s): '.implode(', ', $missing).'. Use the import template; its first row names the columns.']]);
        }

        return [$map, array_slice($table, 1, null, true)];
    }

    /** @return array{0: array, 1: ?array, 2: list<array>} ProductInput, [warehouse, qty, cost] or null, errors */
    private function row(int $n, array $r, array $l, array &$codes, array &$barcodes): array
    {
        $rowNo = $n + 1;
        $errors = [];
        $err = function (string $column, string $message, string $code = 'validation_failed') use (&$errors, $rowNo) {
            $errors[] = ['row' => $rowNo, 'column' => $column, 'code' => $code, 'message' => $message];
        };
        $find = function (string $column, string $kind) use ($r, $l, $err) {
            $v = $r[$column] ?? '';
            if ($v === '') {
                return null;
            }
            $hit = $l[$kind][mb_strtolower($v)] ?? null;
            if (! $hit) {
                $err($column, "No {$kind} \"{$v}\"");
            } elseif (! $hit['active']) {
                $err($column, "\"{$v}\" is inactive", 'inactive_reference');
            }

            return $hit['id'] ?? null;
        };

        $type = strtolower($r['item_type'] ?? '') ?: 'product';
        if (! in_array($type, ['product', 'service'], true)) {
            $err('item_type', 'Type is product or service');
        }
        if ($r['name'] === '' || mb_strlen($r['name']) > 190) {
            $err('name', 'Enter the product name (up to 190 characters)');
        }
        $code = strtoupper($r['code'] ?? '');
        if ($code !== '') {
            if (! preg_match('/^[A-Z0-9][A-Z0-9-]{0,29}$/', $code)) {
                $err('code', 'Codes use letters, digits and dashes (up to 30)');
            } elseif (isset($codes[$code]) || isset($l['codes'][$code])) {
                $err('code', "Code {$code} is already used".(isset($codes[$code]) ? " on row {$codes[$code]}" : ''), 'duplicate');
            }
            $codes[$code] ??= $rowNo;
        }
        $barcode = $r['barcode'] ?? '';
        if ($barcode !== '') {
            if (isset($barcodes[$barcode]) || isset($l['barcodes'][$barcode])) {
                $err('barcode', "Barcode {$barcode} is already used".(isset($barcodes[$barcode]) ? " on row {$barcodes[$barcode]}" : ''), 'duplicate');
            }
            $barcodes[$barcode] ??= $rowNo;
        }
        foreach (['selling_price' => self::MONEY, 'wholesale_price' => self::MONEY, 'purchase_cost' => self::COST, 'opening_qty' => self::QTY,
            'opening_cost' => self::COST, 'reorder_level' => self::QTY, 'reorder_qty' => self::QTY] as $col => $re) {
            $v = str_replace(',', '', $r[$col] ?? '');
            $r[$col] = $v;
            if ($v !== '' && ! preg_match($re, $v)) {
                $err($col, "\"{$v}\" is not a valid number here");
            }
        }
        if ($r['selling_price'] === '') {
            $err('selling_price', 'Enter the selling price');
        }
        if ($r['unit'] === '') {
            $err('unit', 'Enter the unit');
        }
        $unit = $find('unit', 'unit');
        $category = $find('category', 'category');
        $group = $find('stock_group', 'stock group');
        $tax = $find('tax_rule', 'tax rule');
        $supplier = $find('preferred_supplier', 'supplier');
        $warehouse = $find('warehouse', 'warehouse');
        $income = ($r['income_ledger'] ?? '') !== '' ? $find('income_ledger', 'income ledger') : $l['income'][$type === 'service' ? 'service' : 'product'];

        $qty = Quantity::of($r['opening_qty'] !== '' && preg_match(self::QTY, $r['opening_qty']) ? $r['opening_qty'] : '0');
        if ($qty->isPositive()) {
            if ($type === 'service') {
                $err('opening_qty', 'A service keeps no stock');
            }
            if (! $warehouse && ($r['warehouse'] ?? '') === '') {
                $err('warehouse', 'Enter the warehouse that holds the opening stock');
            }
            if ($r['opening_cost'] === '') {
                $err('opening_cost', 'Enter the cost per unit of the opening stock');
            }
        }
        if ((($r['reorder_level'] ?? '') !== '' || ($r['reorder_qty'] ?? '') !== '') && ! $warehouse && ($r['warehouse'] ?? '') === '') {
            $err('warehouse', 'Enter the warehouse the reorder level is for');
        }
        if ($errors) {
            return [[], null, $errors];
        }

        $input = array_filter([
            'item_type' => $type, 'name' => $r['name'], 'code' => $code ?: null, 'category_id' => $category, 'unit_id' => $unit, 'barcode' => $barcode ?: null,
            'selling_price' => $r['selling_price'], 'wholesale_price' => $r['wholesale_price'] ?: null, 'last_purchase_cost' => $r['purchase_cost'] ?: null,
            'tax_rule_id' => $tax, 'income_ledger_id' => $income, 'stock_group_id' => $group, 'preferred_supplier_id' => $supplier,
        ], fn ($v) => $v !== null);
        if ($warehouse && $type === 'product' && (($r['reorder_level'] ?? '') !== '' || ($r['reorder_qty'] ?? '') !== '')) {
            $input['warehouses'] = [['warehouse_id' => $warehouse, 'reorder_level' => $r['reorder_level'] ?: '0', 'reorder_qty' => $r['reorder_qty'] ?: '0']];
        }

        return [$input, $qty->isPositive() ? [$warehouse, $qty, UnitCost::of($r['opening_cost'])] : null, []];
    }

    /** Names (lower case) → ['id', 'active'] for every reference a row may name, plus existing codes and barcodes. */
    private function lookups(): array
    {
        $by = fn ($rows, string $key = 'name', string $active = 'is_active') => $rows->mapWithKeys(fn ($x) => [mb_strtolower((string) $x->{$key}) => ['id' => $x->id,
            'active' => $active === 'status' ? $x->status !== 'inactive' : (bool) ($x->{$active} ?? true)]])->all();
        $warehouses = Warehouse::get(['id', 'code', 'name', 'status']);
        $suppliers = Party::where('party_type', 'supplier')->get(['id', 'code', 'name', 'status']);
        $ledgers = Ledger::get(['id', 'code', 'name', 'is_active']);
        $sales = $this->mapper->ledgerFor('sales_default')->id;

        return [
            'unit' => $by(Unit::get(['id', 'name'])), 'category' => $by(ProductCategory::get(['id', 'name'])),
            'stock group' => $by(StockGroup::get(['id', 'name'])), 'tax rule' => $by(TaxRule::get(['id', 'name', 'is_active'])),
            'supplier' => $by($suppliers, 'code', 'status') + $by($suppliers, 'name', 'status'),
            'warehouse' => $by($warehouses, 'code', 'status') + $by($warehouses, 'name', 'status'),
            'income ledger' => $by($ledgers, 'code'),
            'income' => ['product' => $sales, 'service' => $ledgers->firstWhere('code', '4201')?->id ?? $sales],
            'codes' => Product::withTrashed()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all(),
            'barcodes' => Product::withTrashed()->whereNotNull('barcode')->pluck('id', 'barcode')->all(),
        ];
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
