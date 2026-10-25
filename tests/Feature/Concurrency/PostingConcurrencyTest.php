<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Access\CompanyProvisioner;
use App\Domain\Accounting\BooksIntegrity;
use App\Domain\Inventory\ProductService;
use App\Domain\Inventory\WarehouseService;
use App\Domain\Parties\PartyService;
use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

/**
 * Concurrency and failure tests with real parallel processes (09 §9; TC-CON-001, TC-CON-003, TC-CON-004, TC-CON-005;
 * backlog P4.3-BE-04, P5.1-BE-03). The children must see committed rows, so this class commits for real and truncates afterwards
 * (DatabaseTruncation, not the rolled-back transaction of the other feature tests). Children run
 * tests/Concurrency/post-bill.php (or post-invoice.php) against the same test database and start together on a barrier file.
 */
class PostingConcurrencyTest extends BaseTestCase
{
    use DatabaseTruncation;

    private Company $company;

    private User $user;

    private array $products = [];

    private int $warehouse;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->user = User::create(['name' => 'Accountant', 'email' => 'acc@meghna.example', 'password_hash' => Hash::make('correct horse battery'), 'status' => 'active']);
        $this->company = app(CompanyProvisioner::class)->provision('Meghna Traders Ltd.', $this->user);
        app(CurrentCompany::class)->set($this->company, null);
        Auth::setUser($this->user);
        $this->supplier = app(PartyService::class)->create(['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333', 'default_currency_code' => 'BDT'])['party']->id;
        $this->warehouse = app(WarehouseService::class)->create(['code' => 'WH-TEJ', 'name' => 'Tejgaon warehouse', 'branch_id' => DB::table('branches')->where('company_id', $this->company->id)->value('id')])->id;
        foreach (['Soybean oil 5L', 'Rice 50kg', 'Lentils 1kg'] as $name) {
            $this->products[] = app(ProductService::class)->create(['item_type' => 'product', 'name' => $name, 'selling_price' => '1.00',
                'unit_id' => DB::table('units')->where('company_id', $this->company->id)->value('id'),
                'income_ledger_id' => DB::table('ledgers')->where('company_id', $this->company->id)->where('code', '4101')->value('id')])->id;
        }
    }

    /**
     * DatabaseTruncation empties the tables before each of its tests, not after the last one. The rows these tests
     * commit would then sit under the rolled-back tests that run later in the same suite, so empty them here too.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    private function bill(string $supplierBillNo, array $productIds): int
    {
        app(CurrentCompany::class)->set($this->company, null);

        return app(PurchaseBillService::class)->createDraft(['party_id' => $this->supplier, 'supplier_bill_no' => $supplierBillNo,
            'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $this->warehouse,
            'lines' => array_map(fn ($id) => ['product_id' => $id, 'quantity' => '10', 'unit_cost' => '100.00'], $productIds)])->id;
    }

    /** Starts one child per document, releases them together, and returns their JSON results. */
    private function postInParallel(array $billIds, string $script = 'post-bill.php'): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'pluto-barrier-');
        unlink($barrier);
        $procs = [];
        foreach ($billIds as $id) {
            $p = new Process([PHP_BINARY, base_path("tests/Concurrency/{$script}"), (string) $this->company->id, (string) $this->user->id, (string) $id, $barrier],
                base_path(), ['DB_DATABASE' => config('database.connections.mysql.database'), 'APP_ENV' => 'testing'], null, 120);
            $p->start();
            $procs[] = $p;
        }
        usleep(1_500_000); // let both children boot and wait on the barrier
        touch($barrier);
        $out = [];
        foreach ($procs as $p) {
            $p->wait();
            $out[] = json_decode(trim($p->getOutput()), true) ?? ['ok' => false, 'code' => 'no-output', 'message' => $p->getErrorOutput()];
        }
        @unlink($barrier);

        return $out;
    }

    private function assertBooks(): void
    {
        app(CurrentCompany::class)->set($this->company, null);
        $this->assertSame([], app(BooksIntegrity::class)->failures($this->company->id));
    }

    public function test_tc_con_005_two_bills_sharing_products_in_opposite_order_never_deadlock(): void
    {
        [$a, $b, $c] = $this->products;
        for ($round = 1; $round <= 5; $round++) { // "stable in 5 consecutive runs"
            $results = $this->postInParallel([$this->bill("A-{$round}", [$a, $b, $c]), $this->bill("B-{$round}", [$c, $b, $a])]);
            $this->assertSame([['ok' => true], ['ok' => true]], $results, "round {$round}: ".json_encode($results));
        }
        app(CurrentCompany::class)->set($this->company, null);
        $this->assertSame(10, PurchaseBill::where('status', 'posted')->count());
        $this->assertSame('100.000', DB::table('stock_balances')->where('product_id', $a)->value('qty_on_hand'), '10 bills × 10');
        $this->assertBooks();
    }

    public function test_tc_con_001_two_invoices_for_the_last_10_units_one_posts(): void
    {
        [$oil] = $this->products;
        app(PurchaseBillPostingService::class)->post(PurchaseBill::findOrFail($this->bill('STOCK-1', [$oil]))); // 10 on hand
        $customer = app(PartyService::class)->create(['party_type' => 'customer', 'name' => 'Karim Distributors', 'mobile' => '01711458230', 'default_currency_code' => 'BDT'])['party']->id;
        $invoice = fn () => app(SalesInvoiceService::class)->createDraft(['party_id' => $customer, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $this->warehouse, 'lines' => [['product_id' => $oil, 'quantity' => '10']]])->id;
        $results = $this->postInParallel([$invoice(), $invoice()], 'post-invoice.php');
        $codes = array_map(fn ($r) => $r['ok'] ? 'posted' : $r['code'], $results);
        sort($codes);
        $this->assertSame(['insufficient_stock', 'posted'], $codes, json_encode($results));
        app(CurrentCompany::class)->set($this->company, null);
        $this->assertSame(1, SalesInvoice::where('status', 'posted')->count());
        $this->assertSame('0.000', DB::table('stock_balances')->where('product_id', $oil)->value('qty_on_hand'), 'never negative');
        $this->assertBooks();
    }

    public function test_tc_con_003_the_same_bill_posted_twice_at_once_posts_once(): void
    {
        $bill = $this->bill('SAME-1', $this->products);
        $results = $this->postInParallel([$bill, $bill]);
        $codes = array_map(fn ($r) => $r['ok'] ? 'posted' : $r['code'], $results);
        sort($codes);
        $this->assertSame(['already_posted', 'posted'], $codes, json_encode($results));
        app(CurrentCompany::class)->set($this->company, null);
        $this->assertSame([1, 3], [JournalEntry::count(), StockMovement::count()]);
        $this->assertBooks();
    }

    public function test_tc_con_004_a_process_killed_mid_post_leaves_nothing_behind(): void
    {
        $bill = $this->bill('KILL-1', $this->products);
        $barrier = tempnam(sys_get_temp_dir(), 'pluto-barrier-');
        $p = new Process([PHP_BINARY, base_path('tests/Concurrency/post-bill.php'), (string) $this->company->id, (string) $this->user->id, (string) $bill, $barrier, 'hold'],
            base_path(), ['DB_DATABASE' => config('database.connections.mysql.database'), 'APP_ENV' => 'testing'], null, 120);
        $p->start();
        $p->waitUntil(fn ($type, $out) => str_contains($out, 'uncommitted'));
        $p->signal(SIGKILL);
        $p->wait();
        @unlink($barrier);

        app(CurrentCompany::class)->set($this->company, null);
        $this->assertSame('draft', PurchaseBill::findOrFail($bill)->status);
        $this->assertSame([0, 0, 0], [JournalEntry::count(), StockMovement::count(), (int) DB::table('stock_balances')->where('qty_on_hand', '!=', 0)->count()]);
        // The bill posts normally afterwards: no lock or half-written row was left.
        $this->assertSame([['ok' => true]], $this->postInParallel([$bill]));
        $this->assertBooks();
    }
}
