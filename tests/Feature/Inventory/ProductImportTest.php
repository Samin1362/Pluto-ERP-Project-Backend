<?php

namespace Tests\Feature\Inventory;

use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /products/import and opening stock on product creation (D1, D2; C-05; 06 §11.2; BR-028; backlog P11.1-BE-05). */
class ProductImportTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Warehouse $tej;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->tej = $this->warehouse();
        $this->supplier();
    }

    private function upload(string $name, string $content, bool $dryRun, ?string $token = null)
    {
        $r = $this->call('POST', '/api/v1/products/import'.($dryRun ? '?dry_run=true' : ''), [], [], ['file' => UploadedFile::fake()->createWithContent($name, $content)],
            $this->transformHeadersToServerVars(['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Company-Id' => (string) $this->books->id,
                'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]));
        $this->useBooks();

        return $r;
    }

    private function fyStart(): string
    {
        return (string) DB::table('fiscal_years')->where('company_id', $this->books->id)->whereDate('start_date', '<=', now('Asia/Dhaka')->toDateString())
            ->orderByDesc('start_date')->value('start_date');
    }

    private const GOOD = "Name,Type,Unit,Selling price,Purchase cost,Supplier,Warehouse,Opening qty,Opening cost,Reorder level,Reorder qty\n"
        ."Teer soybean oil 5L jar,product,jar,950,845.00,Bengal Edible Oils,WH-TEJ,18,845,10,24\n"
        ."Home delivery,service,pcs,150,,,,,,,\n";

    public function test_a_dry_run_checks_every_row_and_creates_nothing(): void
    {
        $csv = self::GOOD."Bad price,product,jar,abc,,,,,,,\n,product,bucket,10,,,,,,,\nSpare,product,jar,10,,,WH-TEJ,5,,,\n";
        $r = $this->upload('products.csv', $csv, true)->assertOk();
        $r->assertJsonPath('data.dry_run', true)->assertJsonPath('data.rows', 5)->assertJsonPath('data.valid_rows', 2)->assertJsonPath('data.created_ids', []);
        $this->assertSame([[4, 'selling_price'], [5, 'name'], [5, 'unit'], [6, 'opening_cost']], array_map(fn ($e) => [$e['row'], $e['column']], $r->json('data.errors')));
        $this->assertSame('No unit "bucket"', $r->json('data.errors.2.message'));
        $this->assertSame(0, Product::count());

        // A real run with errors creates nothing either
        $this->upload('products.csv', $csv, false)->assertOk()->assertJsonPath('data.created_ids', [])->assertJsonPath('data.valid_rows', 2);
        $this->assertSame(0, Product::count());
    }

    public function test_a_valid_file_creates_the_products_with_opening_stock_dated_the_fy_start(): void
    {
        $r = $this->upload('products.csv', self::GOOD, false)->assertOk()->assertJsonPath('data.errors', [])->assertJsonPath('data.valid_rows', 2);
        $this->assertCount(2, $r->json('data.created_ids'));
        $oil = Product::where('name', 'Teer soybean oil 5L jar')->sole();
        $this->assertSame(['845.0000', 'Bengal Edible Oils', true], [(string) $oil->last_purchase_cost, $oil->preferredSupplier->name, (bool) $oil->track_stock]);
        $this->assertFalse((bool) Product::where('name', 'Home delivery')->sole()->track_stock);
        $this->assertDatabaseHas('product_warehouse_settings', ['product_id' => $oil->id, 'warehouse_id' => $this->tej->id, 'reorder_level' => '10.000', 'reorder_qty' => '24.000']);

        $b = DB::table('stock_balances')->where('product_id', $oil->id)->where('warehouse_id', $this->tej->id)->first();
        $this->assertSame(['18.000', '15210.00', '845.0000'], [(string) $b->qty_on_hand, (string) $b->total_value, (string) $b->avg_unit_cost]);
        $m = StockMovement::where('product_id', $oil->id)->sole();
        $this->assertSame(['opening', $this->fyStart()], [$m->movement_type, $m->moved_on->toDateString()]);
        $entry = JournalEntry::with('lines.ledger')->findOrFail($m->journal_entry_id);
        $this->assertSame(['opening_balance', $this->fyStart()], [$entry->entry_type, $entry->entry_date->toDateString()]);
        $this->assertSame([['1301-01', '15210.00', '0.00'], ['3301', '0.00', '15210.00']], $entry->lines->map(fn ($l) => [$l->ledger->code, (string) $l->debit, (string) $l->credit])->all());

        // Codes are unique: importing the same code again is refused
        $this->upload('again.csv', "Name,Code,Unit,Selling price\nAgain,{$oil->code},jar,1\n", true)->assertOk()
            ->assertJsonPath('data.errors.0.code', 'duplicate')->assertJsonPath('data.errors.0.column', 'code');
    }

    public function test_xlsx_template_and_file_checks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        foreach ([['Name', 'Unit', 'Selling price'], ['Chinigura rice 25kg', 'bag', 2400]] as $row) {
            $w->addRow(Row::fromValues($row));
        }
        $w->close();
        $r = $this->upload('products.xlsx', (string) file_get_contents($path), false)->assertOk()->assertJsonPath('data.valid_rows', 1);
        $this->assertSame('2400.00', (string) Product::findOrFail($r->json('data.created_ids.0'))->selling_price);

        $this->assertProblem($this->upload('products.csv', "Product,Price\nx,1\n", true), 422, 'validation_failed');
        $this->assertProblem($this->upload('products.pdf', 'x', true), 415, 'file_type_not_allowed');
        $this->userWithRole('viewer', 'viewer@meghna.example');
        $this->assertProblem($this->upload('products.csv', self::GOOD, true, $this->login('viewer@meghna.example')), 403, 'permission_denied');
    }

    public function test_opening_stock_on_product_creation(): void
    {
        $unit = DB::table('units')->where('company_id', $this->books->id)->where('name', 'jar')->value('id');
        $income = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '4101')->value('id');
        $r = $this->api('POST', '/api/v1/products', $this->token, $this->books->id, ['item_type' => 'product', 'name' => 'Rupchanda oil 1L', 'unit_id' => $unit,
            'selling_price' => '190.00', 'income_ledger_id' => $income, 'warehouses' => [['warehouse_id' => $this->tej->id, 'opening_qty' => '40', 'opening_unit_cost' => '170.5']]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->useBooks();
        $r->assertJsonPath('data.on_hand', '40.000')->assertJsonPath('data.stock_value', '6820.00');
        $this->assertSame(1, JournalEntry::where('entry_type', 'opening_balance')->where('source_type', 'products')->where('source_id', $r->json('data.id'))->count());
        $this->assertProblem($this->api('PATCH', '/api/v1/products/'.$r->json('data.id'), $this->token, $this->books->id,
            ['warehouses' => [['warehouse_id' => $this->tej->id, 'opening_qty' => '5']]], ['If-Match' => 'W/"1"']), 422, 'validation_failed');
    }
}
