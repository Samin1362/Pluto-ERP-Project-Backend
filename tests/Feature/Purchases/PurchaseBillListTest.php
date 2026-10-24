<?php

namespace Tests\Feature\Purchases;

use App\Domain\Purchases\PurchaseBillService;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** GET /purchase-bills (F2; backlog P4.2-BE-03): filters, search, sort, paging, isolation. */
class PurchaseBillListTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $bengal;

    private Party $meghna;

    private Warehouse $wh;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->bengal = $this->supplier('Bengal Edible Oils', '01911222333');
        $this->meghna = $this->supplier('Meghna Packaging', '01811000222');
        $this->wh = $this->warehouse();
        $this->oil = $this->product();
        $today = CarbonImmutable::now('Asia/Dhaka');
        $first = CarbonImmutable::parse(DB::table('accounting_periods')->where('company_id', $this->books->id)->where('status', 'open')->min('start_date'));
        // Three bills: an old one that becomes overdue and part paid, a posted current one, a draft.
        $this->bill($this->bengal, 'CST-0918', $first, '100', '10.00');
        $this->bill($this->bengal, 'CST-0919', $today, '50', '20.00');
        $this->bill($this->meghna, 'MP-77', $today, '10', '5.00');
        $this->useBooks();
        PurchaseBill::where('supplier_bill_no', 'CST-0918')->update(['status' => 'posted', 'due_date' => $first->toDateString(), 'payment_status' => 'part_paid',
            'amount_settled' => '400.00', 'balance_due' => '600.00']);
        PurchaseBill::where('supplier_bill_no', 'CST-0919')->update(['status' => 'posted', 'due_date' => $today->addDays(30)->toDateString()]);
    }

    private function bill(Party $p, string $no, CarbonImmutable $date, string $qty, string $cost): void
    {
        $this->useBooks();
        app(PurchaseBillService::class)->createDraft(['party_id' => $p->id, 'supplier_bill_no' => $no, 'bill_date' => $date->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => $qty, 'unit_cost' => $cost]]]);
    }

    private function refs(string $query = ''): array
    {
        $r = $this->api('GET', '/api/v1/purchase-bills'.$query, $this->token, $this->books->id)->assertOk();

        return array_column($r->json('data'), 'supplier_bill_no');
    }

    public function test_list_newest_first_with_header_fields_only(): void
    {
        $r = $this->api('GET', '/api/v1/purchase-bills', $this->token, $this->books->id)->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame(['MP-77', 'CST-0919', 'CST-0918'], array_column($r->json('data'), 'supplier_bill_no'));
        $old = collect($r->json('data'))->firstWhere('supplier_bill_no', 'CST-0918');
        $this->assertSame(['posted', 'part_paid', true, '1000.00', '600.00', 'Bengal Edible Oils'],
            [$old['status'], $old['payment_status'], $old['overdue'], $old['total'], $old['balance_due'], $old['party']['name']]);
        $this->assertSame([[], []], [$old['lines'], $old['journal']], 'list rows carry no lines or journal');
    }

    public function test_filters_search_dates_and_sort(): void
    {
        $this->assertSame(['MP-77'], $this->refs('?status=draft'));
        $this->assertSame(['CST-0919', 'CST-0918'], $this->refs('?status=posted'));
        $this->assertSame(['CST-0918'], $this->refs('?payment_status=part_paid'));
        $this->assertSame(['CST-0918'], $this->refs('?overdue=true'));
        $this->assertSame(['MP-77', 'CST-0919'], $this->refs('?overdue=false'));
        $this->assertSame(['CST-0919', 'CST-0918'], $this->refs("?party_id={$this->bengal->id}"));
        $this->assertSame(['MP-77'], $this->refs('?q=packaging'), 'supplier name');
        $this->assertSame(['CST-0919'], $this->refs('?q=0919'), "supplier's bill number");
        $number = PurchaseBill::where('supplier_bill_no', 'MP-77')->value('number');
        $this->assertSame(['MP-77'], $this->refs('?q='.$number), 'bill number');
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->assertCount(2, $this->refs("?date_from={$today}&date_to={$today}"));
        $this->assertSame(['MP-77', 'CST-0918', 'CST-0919'], $this->refs('?sort=total')); // 50.00, then 1000.00 twice (by id)
        $this->assertSame(['CST-0918', 'CST-0919', 'MP-77'], $this->refs('?sort=number'));
        $this->api('GET', '/api/v1/purchase-bills?per_page=2&page=2', $this->token, $this->books->id)->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);

        foreach (['?status=open', '?overdue=yes', '?sort=supplier', "?date_from={$today}&date_to=2000-01-01", '?warehouse_id=1', '?party_id=999999'] as $bad) {
            $this->assertProblem($this->api('GET', '/api/v1/purchase-bills'.$bad, $this->token, $this->books->id), 422, 'validation_failed');
        }
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('store'); // Store keeper has purchases.view
        $this->api('GET', '/api/v1/purchase-bills', $this->login('store@meghna.example'), $this->books->id)->assertOk()->assertJsonPath('meta.total', 3);
        $this->userWithRole('sales'); // Sales staff has no purchases.view
        $this->assertProblem($this->api('GET', '/api/v1/purchase-bills', $this->login('sales@meghna.example'), $this->books->id), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->api('GET', '/api/v1/purchase-bills', $this->login('padma@agro.example'), $other->id)->assertJsonPath('meta.total', 0);
    }
}
