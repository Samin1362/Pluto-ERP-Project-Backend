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
 * Letters of credit end to end (M1–M8; 06 §12; BR-039–BR-047; TC-LC-001..013; backlog P16.1-BE-01..09), with the worked
 * examples of 06 §12 to the taka: LC-…: US$ 38,400, planning rate 121.50, margin 20 % = ৳ 9,33,120; the import bill
 * books 20,000 kg at 121.50 = ৳ 46,65,600; payment at 122.10 = ৳ 46,88,640 → FX loss ৳ 23,040; charges ৳ 2,40,000,
 * 40 % of the oil already sold → ৳ 1,44,000 to stock, ৳ 96,000 to COGS, +৳ 12.00 a kilo.
 */
class LcTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $straits;

    private Warehouse $wh;

    private Product $oil;

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

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    /** Dr − Cr of a ledger (optionally only the lines tagged with an LC). */
    private function balance(int $ledger, ?int $lc = null): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $ledger)->where('is_posted', 1)->when($lc, fn ($q) => $q->where('letter_of_credit_id', $lc))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b');
    }

    private function openedLc(array $extra = []): array
    {
        $lc = $this->req('POST', '/api/v1/letters-of-credit', [
            'party_id' => $this->straits->id, 'goods_description' => '20,000 kg crude palm oil', 'quantity' => '20000', 'quantity_unit' => 'kg', 'tenor' => 'sight',
            'incoterm' => 'CFR Chattogram', 'currency_code' => 'USD', 'amount' => '38400.00', 'tolerance_pct' => '5', 'planning_rate' => '121.50', 'margin_pct' => '20',
            'margin_account_id' => $this->bank['id'], 'partial_shipments' => true, 'documents' => [['name' => 'Bill of lading', 'provided_by' => 'Supplier'],
                ['name' => 'Commercial invoice', 'provided_by' => 'Supplier']], 'shipment' => ['eta' => $this->today, 'mode' => 'sea'],
        ] + $extra)->assertCreated();
        $id = $lc->json('data.id');
        $this->assertMatchesRegularExpression('/^LC-\d{4}-\d{3}$/', $lc->json('data.lc_number'));
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'submit'], ['If-Match' => 'W/"1"']), 422, 'lc_submit_incomplete')
            ->assertJsonStructure(['errors' => ['issuing_bank_account_id', 'expiry_date']]);
        $this->req('PUT', "/api/v1/letters-of-credit/{$id}", ['party_id' => $this->straits->id, 'goods_description' => '20,000 kg crude palm oil', 'tenor' => $extra['tenor'] ?? 'sight',
            'usance_days' => $extra['usance_days'] ?? null, 'incoterm' => 'CFR Chattogram', 'currency_code' => 'USD', 'amount' => '38400.00', 'tolerance_pct' => '5', 'planning_rate' => '121.50',
            'margin_pct' => '20', 'margin_account_id' => $this->bank['id'], 'partial_shipments' => true, 'issuing_bank_account_id' => $this->bank['id'],
            'expiry_date' => CarbonImmutable::parse($this->today)->addDays(60)->toDateString()], ['If-Match' => 'W/"1"'])->assertOk();
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'submit'], ['If-Match' => 'W/"2"'])->assertOk()->assertJsonPath('data.status', 'awaiting_bank');
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'mark_opened'], ['If-Match' => 'W/"3"']), 422, 'validation_failed');
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'mark_opened', 'bank_reference' => 'SBL/LC/2026/0419', 'date' => $this->today], ['If-Match' => 'W/"3"'])
            ->assertOk()->assertJsonPath('data.status', 'opened')->assertJsonPath('data.margin_amount', '933120.00');

        return $this->req('GET', "/api/v1/letters-of-credit/{$id}")->json('data');
    }

    private function postDoc(string $resource, array $body): array
    {
        $d = $this->req('POST', "/api/v1/{$resource}", $body)->assertCreated()->json('data');

        return $this->req('POST', "/api/v1/{$resource}/{$d['id']}/post")->assertOk()->json('data');
    }

    public function test_t_c_lc_004_006_008_009_the_worked_examples_to_the_taka(): void
    {
        $lc = $this->openedLc();
        $id = $lc['id'];
        // Margin deposit (BR-044): Dr 1403 tagged / Cr bank.
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'margin_deposit', 'charge_date' => $this->today, 'amount' => '933120.00',
            'status' => 'paid', 'paid_from_account_id' => $this->bank['id']])->assertCreated()->assertJsonPath('data.is_refundable', true)->assertJsonPath('data.include_in_landed_cost', false);
        $this->assertSame('933120.00', $this->balance($this->ledger('1403'), $id));

        // Charges ৳ 2,40,000 into goods in transit: commission paid, insurance paid, C&F agent unpaid (payable to the agent).
        $agent = $this->supplier('Karnaphuli C&F', '01711000802');
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'opening_commission', 'charge_date' => $this->today, 'amount' => '40000.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id']])->assertCreated();
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'marine_insurance', 'charge_date' => $this->today, 'amount' => '50000.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id']])->assertCreated();
        $cf = $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'cf_agent_fee', 'charge_date' => $this->today, 'amount' => '150000.00', 'status' => 'unpaid',
            'payee_party_id' => $agent->id])->assertCreated()->json('data');
        $this->assertSame('240000.00', $this->balance($this->ledger('1404'), $id));
        // A bank fee kept out of landed cost goes to bank charges.
        $fee = $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'swift_postage', 'charge_date' => $this->today, 'amount' => '1500.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id'], 'include_in_landed_cost' => false])->assertCreated()->json('data');
        $this->assertSame('1500.00', $this->balance($this->ledger('6702'), $id));
        $this->req('POST', "/api/v1/lc-charges/{$fee['id']}/reverse", ['reason' => 'Bank refunded it'])->assertOk()->assertJsonPath('data.posting_status', 'reversed');
        $this->assertSame('0.00', $this->balance($this->ledger('6702'), $id));

        // Import bill first, at the planning rate (F3 import mode): 20,000 kg × US$ 1.92 = US$ 38,400 × 121.50 = ৳ 46,65,600.
        $bill = $this->postDoc('purchase-bills', ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-CI-88', 'bill_date' => $this->today, 'warehouse_id' => $this->wh->id,
            'letter_of_credit_id' => $id, 'lc_shipment_id' => $lc['shipments'][0]['id'], 'lines' => [['product_id' => $this->oil->id, 'quantity' => '20000', 'unit_cost' => '1.92']]]);
        $this->assertSame(['USD', '121.500000', '4665600.00'], [$bill['currency_code'], $bill['exchange_rate'], $bill['total']]);

        // TC-LC-004 payment at the bank's rate: US$ 38,400 × 122.10 = ৳ 46,88,640; margin 9,33,120; bank 37,55,520.
        $bankBefore = $this->balance((int) $this->bank['ledger_id']);
        $pay = $this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->today, 'amount_foreign' => '38400.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id'], 'margin_applied' => '933120.00'])->assertCreated();
        $pay->assertJsonPath('data.amount_bdt', '4688640.00')->assertJsonPath('data.margin_applied', '933120.00')->assertJsonPath('data.fx_difference', '23040.00');
        $lines = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $pay->json('data.journal_entry_id'))->orderBy('l.line_no')
            ->get(['g.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
        $this->assertSame([[$this->straits->ledger->code, '4688640.00', '0.00'], ['1403', '0.00', '933120.00'], [DB::table('ledgers')->where('id', $this->bank['ledger_id'])->value('code'), '0.00', '3755520.00']], $lines);
        $this->assertSame((string) bcsub($bankBefore, '3755520.00', 2), $this->balance((int) $this->bank['ledger_id']));

        // TC-LC-006: FX loss ৳ 23,040 Dr 6603 / Cr supplier; the bill is paid and the supplier's taka balance is zero.
        $this->assertSame('23040.00', $this->balance($this->ledger('6603')));
        $this->req('GET', "/api/v1/purchase-bills/{$bill['id']}")->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.balance_due', '0.00');
        $this->assertSame('0.00', $this->balance($this->straits->ledger_id));

        // Sell 8,000 kg (40 %), then TC-LC-008 landed cost: ৳ 1,44,000 to stock, ৳ 96,000 to COGS, +৳ 12.00 a kilo.
        $karim = $this->customer();
        $this->postDoc('sales-invoices', ['party_id' => $karim->id, 'invoice_date' => $this->today, 'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '8000']]]);
        $preview = $this->req('POST', "/api/v1/letters-of-credit/{$id}/landed-cost/preview", ['purchase_bill_id' => $bill['id'], 'allocation_date' => $this->today])->assertOk();
        $preview->assertJsonPath('data.status', 'preview')->assertJsonPath('data.total_amount', '240000.00')->assertJsonPath('data.lines.0.amount_to_inventory', '144000.00')
            ->assertJsonPath('data.lines.0.amount_to_cogs', '96000.00')->assertJsonPath('data.lines.0.per_unit', '12.0000');
        $avgBefore = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->wh->id)->value('avg_unit_cost');
        $alloc = $this->req('POST', "/api/v1/letters-of-credit/{$id}/landed-cost", ['purchase_bill_id' => $bill['id'], 'allocation_date' => $this->today])->assertCreated();
        $alloc->assertJsonPath('data.status', 'posted');
        $this->assertSame('0.00', $this->balance($this->ledger('1404'), $id));
        $avgAfter = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->wh->id)->value('avg_unit_cost');
        $this->assertSame('12.0000', bcsub($avgAfter, $avgBefore, 4));
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'landed_cost')->where('value_in', '144000.00')->count());
        $this->assertProblem($this->req('POST', "/api/v1/lc-charges/{$cf['id']}/reverse", ['reason' => 'x']), 409, 'charge_allocated');

        $cost = $this->req('GET', "/api/v1/letters-of-credit/{$id}/cost-summary")->assertOk();
        $cost->assertJsonPath('data.totals.landed_cost', '4905600.00')->assertJsonPath('data.totals.per_unit', '245.2800')->assertJsonPath('data.checks.0.ok', true);

        // TC-LC-009 close: blocked while the C&F fee is unpaid and documents are pending; then closes, margin fully applied.
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/close", []), 422, 'lc_not_closable');
        $this->req('POST', "/api/v1/lc-charges/{$cf['id']}/pay", ['paid_from_account_id' => $this->bank['id'], 'date' => $this->today])->assertOk()->assertJsonPath('data.status', 'paid');
        foreach ($this->req('GET', "/api/v1/letters-of-credit/{$id}/documents")->json('data') as $d) {
            $this->req('PATCH', "/api/v1/lc-documents/{$d['id']}", ['status' => 'received'], ['If-Match' => "W/\"{$d['version']}\""])->assertOk()->assertJsonPath('data.status', 'received');
        }
        $closed = $this->req('POST', "/api/v1/letters-of-credit/{$id}/close", [])->assertOk();
        $closed->assertJsonPath('data.status', 'closed')->assertJsonPath('data.money_position.margin_held', '0.00');
        $this->assertProblem($this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'other', 'charge_date' => $this->today, 'amount' => '10.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id']]), 409, 'invalid_transition');
        $this->assertBooksAreConsistent();
        $this->artisan('pluto:integrity')->assertSuccessful();
    }

    public function test_t_c_lc_005_payment_first_uses_its_rate_tolerance_and_reversal(): void
    {
        $lc = $this->openedLc();
        $id = $lc['id'];
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->today, 'amount_foreign' => '40500.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id']]), 422, 'exceeds_tolerance');
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->today, 'amount_foreign' => '100.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id'], 'margin_applied' => '5000.00']), 422, 'margin_exceeds_deposit');
        $p = $this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->today, 'amount_foreign' => '38400.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id']])->assertCreated()->json('data');
        $this->assertSame('4688640.00', $this->balance($this->straits->ledger_id), 'an advance until the bill');
        $bill = $this->postDoc('purchase-bills', ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-CI-89', 'bill_date' => $this->today, 'warehouse_id' => $this->wh->id,
            'letter_of_credit_id' => $id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '20000', 'unit_cost' => '1.92']]]);
        $this->assertSame(['122.100000', '4688640.00', 'paid'], [$bill['exchange_rate'], $bill['total'], $this->req('GET', "/api/v1/purchase-bills/{$bill['id']}")->json('data.payment_status')],
            'the bill takes the payment rate: no FX line (06 §12.5)');
        $this->assertSame('0.00', $this->balance($this->ledger('6603')));

        $this->req('POST', "/api/v1/lc-payments/{$p['id']}/reverse", ['reason' => 'Wrong account'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->req('GET', "/api/v1/purchase-bills/{$bill['id']}")->assertJsonPath('data.balance_due', '4688640.00')->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertProblem($this->req('POST', "/api/v1/lc-payments/{$p['id']}/reverse", ['reason' => 'again']), 409, 'already_reversed');
        $this->assertBooksAreConsistent();
    }

    public function test_t_c_lc_011_013_usance_acceptance_and_settlement_at_maturity(): void
    {
        $lc = $this->openedLc(['tenor' => 'usance', 'usance_days' => 90]);
        $id = $lc['id'];
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_kind' => 'sight_payment', 'payment_date' => $this->today, 'amount_foreign' => '100.00',
            'bank_rate' => '122', 'money_account_id' => $this->bank['id']]), 422, 'validation_failed');
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_kind' => 'usance_acceptance', 'payment_date' => $this->today, 'amount_foreign' => '38400.00',
            'bank_rate' => '122.00', 'money_account_id' => $this->bank['id']])->assertCreated()->assertJsonPath('data.amount_bdt', '4684800.00');
        $this->assertSame('-4684800.00', $this->balance($this->ledger('2302'), $id));
        $s = $this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_kind' => 'usance_settlement', 'payment_date' => $this->today, 'amount_foreign' => '38400.00',
            'bank_rate' => '123.00', 'money_account_id' => $this->bank['id']])->assertCreated();
        $s->assertJsonPath('data.amount_bdt', '4723200.00')->assertJsonPath('data.fx_difference', '38400.00');
        $this->assertSame('0.00', $this->balance($this->ledger('2302'), $id));
        $this->assertSame('38400.00', $this->balance($this->ledger('6603')), '(123.00 − 122.00) × 38,400');
        $this->assertBooksAreConsistent();
    }

    public function test_recoverable_import_tax_amendments_shipments_templates_and_reads(): void
    {
        $lc = $this->openedLc();
        $id = $lc['id'];
        $vat = $this->req('POST', '/api/v1/tax-rules', ['name' => 'Import VAT 15%', 'rate' => '15', 'applies_to' => 'purchases', 'create_ledger' => true, 'is_recoverable' => true])->json('data');
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'customs_duty_taxes', 'charge_date' => $this->today, 'amount' => '70000.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id'], 'tax_rule_id' => $vat['id']])->assertCreated()->assertJsonPath('data.include_in_landed_cost', false)->assertJsonPath('data.tax_rule_id', $vat['id']);
        $this->assertSame('70000.00', $this->balance($vat['ledger_id']));
        $this->assertSame('0.00', $this->balance($this->ledger('1404'), $id), 'recoverable tax is not landed cost (owner decision 1 Nov 2026)');

        // TC-LC-003 amendment: no change until accepted; accepted updates the LC; the bank fee is a charge.
        $newExpiry = CarbonImmutable::parse($this->today)->addDays(90)->toDateString();
        $a = $this->req('POST', "/api/v1/letters-of-credit/{$id}/amendments", ['requested_on' => $this->today, 'status' => 'requested', 'changes' => [['field_key' => 'expiry_date', 'new_value' => $newExpiry]],
            'bank_fee' => '2500.00'])->assertCreated()->assertJsonPath('data.amendment_no', 1)->assertJsonPath('data.changes.0.new_value', $newExpiry);
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.expiry_date', CarbonImmutable::parse($this->today)->addDays(60)->toDateString());
        $this->req('PATCH', '/api/v1/lc-amendments/'.$a->json('data.id'), ['status' => 'issued', 'date' => $this->today], ['If-Match' => 'W/"1"'])->assertOk();
        $this->req('PATCH', '/api/v1/lc-amendments/'.$a->json('data.id'), ['status' => 'accepted', 'date' => $this->today], ['If-Match' => 'W/"2"'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.expiry_date', $newExpiry);
        $this->assertSame(1, DB::table('lc_charges')->where('letter_of_credit_id', $id)->where('charge_type', 'amendment_fee')->where('amount', '2500.00')->count());
        $this->req('GET', '/api/v1/lc-amendments?status=accepted')->assertOk()->assertJsonPath('meta.total', 1);

        // Lots: the first one at sea moves the LC to partly shipped.
        $lot = $lc['shipments'][0];
        $this->req('PATCH', "/api/v1/lc-shipments/{$lot['id']}", ['status' => 'at_sea', 'bl_number' => 'MAEU-1234', 'shipped_on' => $this->today], ['If-Match' => "W/\"{$lot['version']}\""])->assertOk();
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.status', 'partly_shipped');
        $this->req('POST', "/api/v1/letters-of-credit/{$id}/shipments", ['status' => 'not_shipped'])->assertCreated()->assertJsonPath('data.lot_no', 2);

        // Documents and templates (R-40): a draft LC copies a template's items.
        $tpl = $this->req('POST', '/api/v1/lc-document-templates', ['name' => 'Edible oil', 'is_default' => true, 'items' => [['name' => 'Phytosanitary certificate', 'provided_by' => 'Supplier'],
            ['name' => 'Certificate of origin', 'provided_by' => 'Chamber of commerce', 'copies' => 2]]])->assertCreated()->json('data');
        $draft = $this->req('POST', '/api/v1/letters-of-credit', ['party_id' => $this->straits->id, 'goods_description' => 'More oil', 'tenor' => 'sight', 'incoterm' => 'FOB', 'currency_code' => 'USD',
            'amount' => '1000.00', 'planning_rate' => '121.50', 'document_template_id' => $tpl['id']])->assertCreated();
        $this->assertSame(['Phytosanitary certificate', 'Certificate of origin'], array_column($draft->json('data.documents'), 'name'));
        $this->assertProblem($this->req('PATCH', '/api/v1/lc-documents/'.$draft->json('data.documents.0.id'), ['status' => 'discrepant'], ['If-Match' => 'W/"1"']), 422, 'validation_failed');
        $this->req('DELETE', '/api/v1/letters-of-credit/'.$draft->json('data.id'))->assertNoContent();
        $this->assertProblem($this->req('DELETE', "/api/v1/letters-of-credit/{$id}"), 409, 'not_draft');

        // Reads: list, overview, matrix, reports, PDF.
        $this->req('GET', '/api/v1/letters-of-credit?group=active&q=palm')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->req('GET', '/api/v1/lc/overview')->assertOk()->assertJsonPath('data.totals.active', 1);
        $this->req('GET', '/api/v1/lc-documents/matrix')->assertOk()->assertJsonPath('data.rows.0.total', 2);
        foreach (['register', 'status', 'outstanding', 'expiry', 'documents', 'amendments', 'charges', 'cost'] as $r) {
            $this->req('GET', "/api/v1/reports/lc/{$r}")->assertOk()->assertJsonStructure(['data' => ['title', 'columns', 'rows']]);
        }
        $this->req('GET', '/api/v1/reports/lc/outstanding')->assertJsonPath('data.rows.0.outstanding_foreign', '38400.00');
        $this->assertStringStartsWith('%PDF', $this->req('GET', "/api/v1/letters-of-credit/{$id}/pdf")->assertOk()->getContent());
        $this->req('GET', '/api/v1/reports/lc/everything')->assertNotFound();
        $this->assertBooksAreConsistent();
    }

    public function test_t_c_lc_001_002_status_machine_expiry_and_permissions(): void
    {
        $lc = $this->openedLc();
        $id = $lc['id'];
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'submit'], ['If-Match' => "W/\"{$lc['version']}\""]), 409, 'invalid_transition');
        $this->assertProblem($this->req('PUT', "/api/v1/letters-of-credit/{$id}", ['party_id' => $this->straits->id, 'goods_description' => 'x', 'tenor' => 'sight', 'incoterm' => 'FOB',
            'currency_code' => 'USD', 'amount' => '1.00', 'planning_rate' => '1'], ['If-Match' => "W/\"{$lc['version']}\""]), 409, 'invalid_transition');
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'margin_deposit', 'charge_date' => $this->today, 'amount' => '100000.00', 'status' => 'paid',
            'paid_from_account_id' => $this->bank['id']])->assertCreated();
        $this->artisan('pluto:lc:expire', ['--date' => CarbonImmutable::parse($this->today)->addDays(61)->toDateString()])->expectsOutputToContain('1 LC(s) expired');
        $this->req('GET', "/api/v1/letters-of-credit/{$id}")->assertJsonPath('data.status', 'expired')->assertJsonPath('data.money_position.margin_held', '0.00');

        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', '/api/v1/letters-of-credit', token: $this->login('sales@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/letters-of-credit/{$id}", token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'other', 'charge_date' => $this->today, 'amount' => '1.00', 'status' => 'unpaid'],
            token: $viewer), 403, 'permission_denied');
        $this->assertBooksAreConsistent();
    }
}
