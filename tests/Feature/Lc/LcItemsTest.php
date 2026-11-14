<?php

namespace Tests\Feature\Lc;

use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Items on a letter of credit (PEP-487; BR-041, BR-056, BR-069): the items entered on an LC are kept as the lines of a
 * purchase order created, sent and linked in the same transaction, so an import bill fills from what is still to come
 * and stays within the LC's tolerance. A draft LC's items are replaced with it; an opened LC's items change only
 * through an accepted amendment; an LC made before items existed gets them once through POST …/items.
 */
class LcItemsTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $straits;

    private Warehouse $wh;

    private Product $oil;

    private Product $ghee;

    private array $bank;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->straits = $this->supplier('Straits Edible Oils', '01711000801');
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Crude palm oil', 'kg', '300.00');
        $this->ghee = $this->product('Vegetable ghee', 'kg', '450.00');
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '10000000.00'])->assertCreated()->json('data');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->straits->id, 'goods_description' => 'Crude palm oil and vegetable ghee', 'tenor' => 'sight', 'incoterm' => 'CFR Chattogram',
            'currency_code' => 'USD', 'amount' => '43400.00', 'tolerance_pct' => '5', 'planning_rate' => '121.50', 'partial_shipments' => true,
            'issuing_bank_account_id' => $this->bank['id'], 'expiry_date' => CarbonImmutable::parse($this->today)->addDays(60)->toDateString()];
    }

    /** 20,000 kg oil at US$ 1.92 and 2,000 kg ghee at US$ 2.50 = US$ 43,400. */
    private function items(): array
    {
        return [['product_id' => $this->oil->id, 'quantity' => '20000', 'unit_cost' => '1.92'],
            ['product_id' => $this->ghee->id, 'quantity' => '2000', 'unit_cost' => '2.5', 'description' => 'In 16 kg tins']];
    }

    private function open(int $id): array
    {
        $v = $this->req('GET', "/api/v1/letters-of-credit/{$id}")->json('data.version');
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'submit'], ['If-Match' => "W/\"{$v}\""])->assertOk();
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'mark_opened', 'bank_reference' => 'SBL/LC/2026/0420', 'date' => $this->today],
            ['If-Match' => 'W/"'.($v + 1).'"'])->assertOk()->assertJsonPath('data.status', 'opened');

        return $this->req('GET', "/api/v1/letters-of-credit/{$id}")->json('data');
    }

    private function openedLcWithItems(): array
    {
        return $this->open($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items()]))->assertCreated()->json('data.id'));
    }

    private function bill(array $lc, array $quantities, array $over = []): array
    {
        $lines = [];
        foreach ($quantities as $i => $q) {
            if ($q !== null) {
                $l = $lc['lines'][$i];
                $lines[] = ['product_id' => $l['product_id'], 'quantity' => $q, 'unit_cost' => $l['unit_cost'], 'purchase_order_line_id' => $l['id']];
            }
        }

        return $over + ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-'.Str::random(6), 'bill_date' => $this->today, 'warehouse_id' => $this->wh->id,
            'letter_of_credit_id' => $lc['id'], 'purchase_order_id' => $lc['purchase_order_id'], 'lines' => $lines];
    }

    public function test_an_lc_created_with_items_keeps_them_on_a_sent_purchase_order(): void
    {
        $r = $this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items(), 'warehouse_id' => $this->wh->id]))->assertCreated();
        $r->assertJsonPath('data.items_source', 'entered')->assertJsonPath('data.lines_total', '43400.00')->assertJsonPath('data.warehouse_id', $this->wh->id)
            ->assertJsonPath('data.version', 1)->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.product_name', 'Crude palm oil')->assertJsonPath('data.lines.0.unit', 'kg')->assertJsonPath('data.lines.0.quantity', '20000.000')
            ->assertJsonPath('data.lines.0.unit_cost', '1.9200')->assertJsonPath('data.lines.0.line_total', '38400.00')->assertJsonPath('data.lines.0.received_quantity', '0.000')
            ->assertJsonPath('data.lines.0.remaining_quantity', '20000.000')->assertJsonPath('data.lines.1.description', 'In 16 kg tins')->assertJsonPath('data.lines.1.line_total', '5000.00');
        $po = $r->json('data.purchase_order_id');
        $this->assertNotNull($po);
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.party.id', $this->straits->id)
            ->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.exchange_rate', '121.500000')->assertJsonPath('data.total_foreign', '43400.00')
            ->assertJsonPath('data.total', '5273100.00')->assertJsonPath('data.warehouse_id', $this->wh->id)->assertJsonPath('data.number', $r->json('data.purchase_order_number'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'purchase_order_sent')->count(), 'no e-mail goes to the supplier');
        $this->req('GET', '/api/v1/letters-of-credit')->assertOk()->assertJsonPath('data.0.items_source', 'entered')->assertJsonPath('data.0.lines_total', '43400.00');

        // The LC PDF lists the items and their total (as the API returns them).
        $lcId = $r->json('data.id');
        $this->assertStringStartsWith('%PDF', $this->req('GET', "/api/v1/letters-of-credit/{$lcId}/pdf")->assertOk()->getContent());
        $html = view('pdf.letter-of-credit', ['lc' => $this->req('GET', "/api/v1/letters-of-credit/{$lcId}")->json('data'), 'company' => $this->books])->render();
        foreach (['<h2>Items</h2>', 'Crude palm oil', 'Vegetable ghee', 'In 16 kg tins', '20000.000', '1.9200', '38400.00', '43400.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // An LC without items says so.
        $this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->assertJsonPath('data.items_source', 'none')->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.lines_total', null)->assertJsonPath('data.purchase_order_id', null);
    }

    public function test_validation_company_isolation_and_permissions(): void
    {
        // Either an existing order or items.
        $po = $this->req('POST', '/api/v1/purchase-orders', ['party_id' => $this->straits->id, 'order_date' => $this->today, 'warehouse_id' => $this->wh->id, 'currency_code' => 'USD',
            'exchange_rate' => '121.500000', 'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '1.92']]])->assertCreated()->json('data.id');
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items(), 'purchase_order_id' => $po])), 422, 'validation_failed')
            ->assertJsonPath('errors.lines.0', 'Choose an existing purchase order or enter the items, not both');
        $this->req('POST', '/api/v1/letters-of-credit', $this->body(['purchase_order_id' => $po]))->assertCreated()->assertJsonPath('data.items_source', 'purchase_order')
            ->assertJsonPath('data.lines_total', '192.00')->assertJsonCount(1, 'data.lines');

        // Line shape: quantity above 0, a cost, a unit the item has.
        $bad = $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '0', 'unit_cost' => '-1'],
            ['quantity' => '1', 'unit_cost' => '1', 'price' => '2']]])), 422, 'validation_failed');
        $bad->assertJsonStructure(['errors' => ['lines.0.quantity', 'lines.0.unit_cost', 'lines.1.product_id', 'lines.1']]);
        $jar = DB::table('units')->where('company_id', $this->books->id)->where('name', 'jar')->value('id');
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => [['product_id' => $this->oil->id, 'unit_id' => $jar, 'quantity' => '1', 'unit_cost' => '1']]])),
            422, 'validation_failed')->assertJsonStructure(['errors' => ['lines.0.unit_id']]);
        DB::table('products')->where('id', $this->ghee->id)->update(['is_active' => 0]);
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items()])), 422, 'inactive_reference')
            ->assertJsonPath('errors', ['lines.1.product_id' => ['Vegetable ghee is inactive']]);
        DB::table('products')->where('id', $this->ghee->id)->update(['is_active' => 1]);
        $this->assertSame(1, DB::table('purchase_orders')->count(), 'a refused LC leaves no order behind');

        // Another company's item or warehouse does not exist here.
        $mine = $this->books;
        $other = $this->provisionedCompany('Padma Agro Ltd.', 'owner@padma.example');
        $theirOil = $this->product('Their oil', 'kg', '100.00');
        $theirWh = $this->warehouse('WH-P', 'Padma godown');
        $this->books = $mine;
        $this->useBooks();
        $this->assertNotSame($mine->id, $other->id);
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => [['product_id' => $theirOil->id, 'quantity' => '1', 'unit_cost' => '1']],
            'warehouse_id' => $theirWh->id])), 422, 'validation_failed')->assertJsonStructure(['errors' => ['lines.0.product_id', 'warehouse_id']]);

        // Permissions: a viewer reads the items but cannot add them.
        $lc = $this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->json('data.id');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/letters-of-credit/{$lc}", token: $viewer)->assertOk()->assertJsonPath('data.items_source', 'none');
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$lc}/items", ['lines' => $this->items()], token: $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items()]), token: $viewer), 403, 'permission_denied');
    }

    public function test_a_draft_lcs_items_are_replaced_with_it_until_a_bill_uses_them(): void
    {
        $lc = $this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items()]))->assertCreated()->json('data');
        $po = $lc['purchase_order_id'];

        // Saving the LC without `lines` leaves the items alone.
        $this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['advising_bank' => 'Maybank Singapore']), ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.items_source', 'entered')->assertJsonPath('data.lines_total', '43400.00')->assertJsonPath('data.version', 2);

        // New lines replace them on the same order; the planning rate follows the LC.
        $r = $this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['planning_rate' => '122.00', 'purchase_order_id' => null,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '15000', 'unit_cost' => '2.00']]]), ['If-Match' => 'W/"2"'])->assertOk();
        $r->assertJsonPath('data.purchase_order_id', $po)->assertJsonPath('data.lines_total', '30000.00')->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.quantity', '15000.000');
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'sent')->assertJsonPath('data.exchange_rate', '122.000000')->assertJsonPath('data.total', '3660000.00');

        // Items and another order together are refused.
        $otherPo = $this->req('POST', '/api/v1/purchase-orders', ['party_id' => $this->straits->id, 'order_date' => $this->today, 'warehouse_id' => $this->wh->id, 'currency_code' => 'USD',
            'exchange_rate' => '121.500000', 'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '1.92']]])->assertCreated()->json('data.id');
        $this->assertProblem($this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['purchase_order_id' => $otherPo, 'lines' => $this->items()]), ['If-Match' => 'W/"3"']),
            422, 'validation_failed');

        // Once a bill (here a draft made from the order) uses the items they no longer change by editing.
        $bill = $this->req('POST', "/api/v1/purchase-orders/{$po}/convert")->assertCreated()->json('data');
        $this->assertSame(['15000.000'], array_column($bill['lines'], 'quantity'));
        $refused = $this->assertProblem($this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['lines' => $this->items()]), ['If-Match' => 'W/"3"']), 409, 'invalid_transition');
        $this->assertStringContainsString($bill['number'], $refused->json('detail'));
        $this->req('DELETE', "/api/v1/purchase-bills/{$bill['id']}")->assertNoContent();

        // An empty list takes the items off: the order is cancelled and unlinked.
        $this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['lines' => []]), ['If-Match' => 'W/"3"'])->assertOk()
            ->assertJsonPath('data.items_source', 'none')->assertJsonPath('data.purchase_order_id', null)->assertJsonPath('data.lines', []);
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'cancelled');

        // Deleting a draft LC cancels the order made from its items.
        $second = $this->req('POST', '/api/v1/letters-of-credit', $this->body(['lines' => $this->items()]))->assertCreated()->json('data');
        $this->req('DELETE', "/api/v1/letters-of-credit/{$second['id']}")->assertNoContent();
        $this->req('GET', "/api/v1/purchase-orders/{$second['purchase_order_id']}")->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_bill_from_the_lc_holds_only_its_items_within_the_tolerance(): void
    {
        $lc = $this->openedLcWithItems();
        $this->assertProblem($this->req('PUT', "/api/v1/letters-of-credit/{$lc['id']}", $this->body(['lines' => $this->items()]), ['If-Match' => "W/\"{$lc['version']}\""]), 409, 'invalid_transition');

        // The order fills a bill with what is still to come (F8 convert, the same lines F3 import mode shows).
        $draft = $this->req('POST', "/api/v1/purchase-orders/{$lc['purchase_order_id']}/convert")->assertCreated()->json('data');
        $this->assertSame([[$this->oil->id, '20000.000', '1.9200'], [$this->ghee->id, '2000.000', '2.5000']],
            array_map(fn ($l) => [$l['product_id'], $l['quantity'], $l['unit_cost']], $draft['lines']));
        $this->assertSame('USD', $draft['currency_code']);
        $this->req('DELETE', "/api/v1/purchase-bills/{$draft['id']}")->assertNoContent();

        // BR-056 with the LC's 5 % tolerance: 21,000 kg of oil at most.
        $over = $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->bill($lc, ['21001', null])), 422, 'order_qty_exceeded');
        $this->assertStringContainsString("incl. the LC's 5% tolerance", $over->json('errors')['lines.0.quantity'][0]);

        // An LC covers only its goods: no bill outside its order, no line that is not one of its items.
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->bill($lc, ['100', null], ['purchase_order_id' => null])), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['purchase_order_id']]);
        $extra = $this->bill($lc, ['100', null]);
        $extra['lines'][] = ['product_id' => $this->ghee->id, 'quantity' => '5', 'unit_cost' => '2.50'];
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $extra), 422, 'validation_failed')
            ->assertJsonPath('errors', ['lines.1.product_id' => ["This item is not on {$lc['lc_number']}; an LC covers only its goods (amend the LC to add it)"]]);

        // A first lot of 12,000 kg posts; the LC shows what arrived and what is still to come.
        $id = $this->req('POST', '/api/v1/purchase-bills', $this->bill($lc, ['12000', null]))->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-bills/{$id}/post")->assertOk()->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.total', '2799360.00');
        $this->req('GET', "/api/v1/letters-of-credit/{$lc['id']}")->assertJsonPath('data.lines.0.received_quantity', '12000.000')->assertJsonPath('data.lines.0.remaining_quantity', '8000.000')
            ->assertJsonPath('data.lines.1.remaining_quantity', '2000.000');
        $this->req('GET', "/api/v1/purchase-orders/{$lc['purchase_order_id']}")->assertJsonPath('data.status', 'partly_received');
        $this->assertBooksAreConsistent();
    }

    public function test_items_are_added_once_to_an_lc_that_has_none(): void
    {
        $lc = $this->open($this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->json('data.id'));
        $this->assertSame('none', $lc['items_source']);
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/items", ['lines' => []]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/items", ['lines' => $this->items(), 'note' => 'x']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/letters-of-credit/999999/items', ['lines' => $this->items()]), 404, 'not_found');

        $key = (string) Str::uuid();
        $r = $this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/items", ['lines' => $this->items(), 'warehouse_id' => $this->wh->id], ['Idempotency-Key' => $key])->assertCreated();
        $r->assertJsonPath('data.items_source', 'entered')->assertJsonPath('data.lines_total', '43400.00')->assertJsonCount(2, 'data.lines')->assertJsonPath('data.status', 'opened')
            ->assertHeader('ETag', 'W/"'.($lc['version'] + 1).'"');
        $this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/items", ['lines' => $this->items(), 'warehouse_id' => $this->wh->id], ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.purchase_order_id', $r->json('data.purchase_order_id'));
        $this->assertSame(1, DB::table('purchase_orders')->count(), 'a replay creates nothing');
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'letters_of_credit')->where('action', 'items_added')->count());
        $this->req('GET', '/api/v1/purchase-orders/'.$r->json('data.purchase_order_id'))->assertJsonPath('data.status', 'sent');

        // Only once: afterwards an opened LC's items change through an amendment.
        $again = $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/items", ['lines' => $this->items()]), 409, 'invalid_transition');
        $this->assertStringContainsString('amendment', $again->json('detail'));

        // Not for an LC that already has a bill, nor a cancelled one.
        $billed = $this->open($this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->json('data.id'));
        $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-CI-1', 'bill_date' => $this->today, 'warehouse_id' => $this->wh->id,
            'letter_of_credit_id' => $billed['id'], 'lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '1.92']]])->assertCreated();
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$billed['id']}/items", ['lines' => $this->items()]), 409, 'invalid_transition');
        $gone = $this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/letters-of-credit/{$gone}/transition", ['action' => 'cancel'], ['If-Match' => 'W/"1"'])->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$gone}/items", ['lines' => $this->items()]), 409, 'invalid_transition');
    }

    public function test_an_accepted_amendment_changes_the_items_of_an_opened_lc(): void
    {
        $lc = $this->openedLcWithItems();
        $id = $lc['id'];
        [$oilLine, $gheeLine] = array_column($lc['lines'], 'id');
        $soap = $this->product('Laundry soap', 'kg', '90.00');
        // 12,000 kg of oil is already received.
        $bill = $this->req('POST', '/api/v1/purchase-bills', $this->bill($lc, ['12000', null]))->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-bills/{$bill}/post")->assertOk();
        $amend = fn (array $lines, array $over = []) => $this->req('POST', "/api/v1/letters-of-credit/{$id}/amendments", $over + ['requested_on' => $this->today, 'status' => 'requested',
            'changes' => [], 'lines' => $lines]);

        // Checked when recorded: never below what bills took, a received item is not removed, each action has what it needs.
        $this->assertProblem($amend([['action' => 'change', 'purchase_order_line_id' => $oilLine, 'quantity' => '11999']]), 422, 'validation_failed')
            ->assertJsonPath('errors', ['lines.0.quantity' => ['Crude palm oil cannot go below 12000.000 kg: that much is already received or on a bill']]);
        $this->assertProblem($amend([['action' => 'remove', 'purchase_order_line_id' => $oilLine]]), 422, 'validation_failed')->assertJsonStructure(['errors' => ['lines.0.purchase_order_line_id']]);
        $this->assertProblem($amend([['action' => 'add', 'product_id' => $soap->id]]), 422, 'validation_failed')->assertJsonStructure(['errors' => ['lines.0.quantity', 'lines.0.unit_cost']]);
        $this->assertProblem($amend([['action' => 'change', 'quantity' => '5']]), 422, 'validation_failed')->assertJsonStructure(['errors' => ['lines.0.purchase_order_line_id']]);
        $this->assertProblem($amend([['action' => 'change', 'purchase_order_line_id' => 999999, 'quantity' => '5']]), 422, 'validation_failed');
        $this->assertProblem($amend([]), 422, 'validation_failed')->assertJsonStructure(['errors' => ['changes']]);

        // Requested: recorded with the values before; the LC does not change yet.
        $a = $amend([['action' => 'change', 'purchase_order_line_id' => $oilLine, 'quantity' => '18000', 'unit_cost' => '1.95'], ['action' => 'remove', 'purchase_order_line_id' => $gheeLine],
            ['action' => 'add', 'product_id' => $soap->id, 'quantity' => '500', 'unit_cost' => '0.80']], ['changes' => [['field_key' => 'amount', 'new_value' => '35500.00']]])->assertCreated();
        $a->assertJsonCount(3, 'data.lines')->assertJsonPath('data.lines.0', ['action' => 'change', 'purchase_order_line_id' => $oilLine, 'product_id' => $this->oil->id,
            'product_code' => $this->oil->code, 'product_name' => 'Crude palm oil', 'unit' => 'kg', 'old_quantity' => '20000.000', 'quantity' => '18000.000', 'old_unit_cost' => '1.9200',
            'unit_cost' => '1.9500'])->assertJsonPath('data.lines.1.action', 'remove')->assertJsonPath('data.lines.1.quantity', null)->assertJsonPath('data.lines.1.old_quantity', '2000.000')
            ->assertJsonPath('data.lines.2.purchase_order_line_id', null)->assertJsonPath('data.lines.2.product_name', 'Laundry soap')->assertJsonPath('data.lines.2.unit_cost', '0.8000');
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.lines_total', '43400.00')->assertJsonCount(2, 'data.lines')->assertJsonPath('data.amendments.0.lines.0.action', 'change');
        $this->req('PATCH', '/api/v1/lc-amendments/'.$a->json('data.id'), ['status' => 'issued', 'date' => $this->today], ['If-Match' => 'W/"1"'])->assertOk();
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.lines_total', '43400.00');

        // Accepted: the items change in place — the oil line keeps its id and what arrived — and the amount with them.
        $this->req('PATCH', '/api/v1/lc-amendments/'.$a->json('data.id'), ['status' => 'accepted', 'date' => $this->today], ['If-Match' => 'W/"2"'])->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $after = $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertOk()->assertJsonPath('data.amount', '35500.00')->assertJsonPath('data.lines_total', '35500.00')->json('data');
        $this->assertSame([[$oilLine, 'Crude palm oil', '18000.000', '1.9500', '35100.00', '12000.000', '6000.000'], ['Laundry soap', '500.000', '0.8000', '400.00', '0.000', '500.000']],
            [array_values(array_intersect_key($after['lines'][0], array_flip(['id', 'product_name', 'quantity', 'unit_cost', 'line_total', 'received_quantity', 'remaining_quantity']))),
                array_values(array_intersect_key($after['lines'][1], array_flip(['product_name', 'quantity', 'unit_cost', 'line_total', 'received_quantity', 'remaining_quantity'])))]);
        $this->assertSame($after['lines'][1]['id'], $after['amendments'][0]['lines'][2]['purchase_order_line_id'], 'the added item names its new line');
        $this->req('GET', "/api/v1/purchase-orders/{$lc['purchase_order_id']}")->assertJsonPath('data.status', 'partly_received')->assertJsonPath('data.total_foreign', '35500.00');
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'letters_of_credit')->where('action', 'amended')->whereNotNull('new_values->item_changes')->count());

        // The new item can now be billed; the removed one cannot.
        $this->req('POST', '/api/v1/purchase-bills', $this->bill($after, [null, '500']))->assertCreated();
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-G-1', 'bill_date' => $this->today,
            'warehouse_id' => $this->wh->id, 'letter_of_credit_id' => $id, 'purchase_order_id' => $lc['purchase_order_id'],
            'lines' => [['product_id' => $this->ghee->id, 'quantity' => '10', 'unit_cost' => '2.50', 'purchase_order_line_id' => $gheeLine]]]), 422, 'validation_failed');

        // Checked again on acceptance: the draft bill above took the 500 kg of soap, so going below it is refused and nothing changes.
        $soapLine = $after['lines'][1]['id'];
        $b = $amend([['action' => 'change', 'purchase_order_line_id' => $soapLine, 'quantity' => '500', 'unit_cost' => '0.85']], ['status' => 'issued'])->assertCreated();
        DB::table('lc_amendment_lines')->where('lc_amendment_id', $b->json('data.id'))->update(['quantity' => '400.000']);
        $this->assertProblem($this->req('PATCH', '/api/v1/lc-amendments/'.$b->json('data.id'), ['status' => 'accepted', 'date' => $this->today],
            ['If-Match' => 'W/"'.$b->json('data.version').'"']), 409, 'invalid_transition');
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.lines.1.unit_cost', '0.8000')->assertJsonPath('data.amendments.1.status', 'issued');

        // Item changes need an LC with items.
        $plain = $this->open($this->req('POST', '/api/v1/letters-of-credit', $this->body())->assertCreated()->json('data.id'));
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$plain['id']}/amendments", ['requested_on' => $this->today, 'status' => 'requested', 'changes' => [],
            'lines' => [['action' => 'add', 'product_id' => $soap->id, 'quantity' => '1', 'unit_cost' => '1']]]), 422, 'validation_failed')->assertJsonStructure(['errors' => ['lines']]);
        $this->assertBooksAreConsistent();
    }
}
