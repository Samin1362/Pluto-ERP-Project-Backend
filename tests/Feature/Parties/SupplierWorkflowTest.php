<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C1 list, C2/C3 add/edit, C4 details, deactivate/reactivate/delete for suppliers (backlog P2.1-BE-01; 05 Parties;
 * BR-006, BR-015, BR-026, BR-049, BR-050, BR-063, R-33; TC-NFR-011). Mirrors CustomerWorkflowTest.
 */
class SupplierWorkflowTest extends TestCase
{
    private Company $company;

    private string $token;

    private const BENGAL = ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils Ltd.', 'category' => 'manufacturer',
        'contact_person' => 'Nasir Uddin', 'mobile' => '01911222333', 'email' => 'sales@bengaloils.example', 'address' => 'Tejgaon I/A',
        'district' => 'Dhaka', 'area' => 'Tejgaon', 'credit_limit' => '2000000.00', 'tax_registration_no' => '000123456-0101'];

    private const GUANGZHOU = ['party_type' => 'supplier', 'name' => 'Guangzhou Packaging Co.', 'category' => 'manufacturer',
        'country_code' => 'cn', 'default_currency_code' => 'CNY', 'port_of_loading' => 'Guangzhou (Nansha)', 'mobile' => '+86 20 3888 1234'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [])
    {
        return $this->api($method, $uri, $this->token, $this->company->id, $data, $headers);
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    public function test_create_supplier_gets_sup_code_creditors_ledger_payable_side_and_audit(): void
    {
        $r = $this->req('POST', '/api/v1/parties', self::BENGAL + ['default_purchase_ledger_id' => $this->ledger('5101')])->assertCreated();
        $r->assertJsonPath('data.code', 'SUP-0001')->assertJsonPath('data.party_type', 'supplier')
            ->assertJsonPath('data.mobile', '01911-222333')->assertJsonPath('data.ledger_code', '2101-001')
            ->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.balance_side', 'cr')
            ->assertJsonPath('data.default_purchase_ledger_id', $this->ledger('5101'))
            ->assertJsonPath('data.tax_registration_no', '000123456-0101')
            ->assertJsonPath('data.country_code', 'BD')->assertJsonPath('data.default_currency_code', 'BDT')->assertJsonPath('data.version', 1);
        $id = $r->json('data.id');
        $this->assertSame('W/"1"', $r->headers->get('ETag'));

        $ledger = DB::table('ledgers')->where('id', $r->json('data.ledger_id'))->first();
        $group = DB::table('account_groups')->where('id', $ledger->account_group_id)->first();
        $this->assertSame(['Bengal Edible Oils Ltd.', 'party', 'Sundry creditors', 'liability'], [$ledger->name, $ledger->ledger_kind, $group->name, $group->nature]);
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'parties')->where('auditable_id', $id)->exists());

        // Supplier and customer numbering are separate series (number sequences 'supplier' / 'customer').
        $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'])
            ->assertCreated()->assertJsonPath('data.code', 'CUS-0001');
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Padma Flour Mills', 'mobile' => '01812000111'])
            ->assertCreated()->assertJsonPath('data.code', 'SUP-0002')->assertJsonPath('data.ledger_code', '2101-002');
    }

    public function test_international_supplier_needs_no_bangladeshi_mobile(): void
    {
        // R-33: outside BD the mobile is optional and keeps its own format; currency and port are stored.
        $r = $this->req('POST', '/api/v1/parties', self::GUANGZHOU)->assertCreated();
        $r->assertJsonPath('data.country_code', 'CN')->assertJsonPath('data.default_currency_code', 'CNY')
            ->assertJsonPath('data.port_of_loading', 'Guangzhou (Nansha)')->assertJsonPath('data.mobile', '+862038881234');
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Chennai Spices', 'country_code' => 'IN', 'default_currency_code' => 'USD'])
            ->assertCreated()->assertJsonPath('data.mobile', null);

        // In Bangladesh the mobile is required and must be 01XXX-XXXXXX.
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Local without mobile']), 422, 'validation_failed')
            ->assertJsonPath('errors.mobile.0', 'Enter a mobile number');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'X', 'mobile' => '+86 20 3888 1234']), 422, 'invalid_mobile');
        $this->assertProblem($this->req('POST', '/api/v1/parties', array_merge(self::GUANGZHOU, ['default_currency_code' => 'XYZ'])), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['default_currency_code']]);
    }

    public function test_default_purchase_ledger_must_be_an_expense_ledger_of_the_company(): void
    {
        foreach (['5101', '6105'] as $ok) {
            $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => "Ok {$ok}", 'mobile' => '01911000'.substr($ok, 1),
                'default_purchase_ledger_id' => $this->ledger($ok)])->assertCreated();
        }
        // Inventory sub-ledger, a system ledger, an income ledger, a party ledger: not offered by the C2 picker either.
        $party = DB::table('parties')->where('name', 'Ok 5101')->value('ledger_id');
        foreach ([$this->ledger('1301-01'), $this->ledger('5001'), $this->ledger('4101'), (int) $party] as $bad) {
            $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bad', 'mobile' => '01911999888',
                'default_purchase_ledger_id' => $bad]), 422, 'validation_failed')
                ->assertJsonPath('errors.default_purchase_ledger_id.0', 'Choose an expense ledger for purchases, e.g. 5101 Purchases, non-stock items');
        }
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $foreign = DB::table('ledgers')->where('company_id', $other->id)->where('code', '5101')->value('id');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bad', 'mobile' => '01911999888',
            'default_purchase_ledger_id' => $foreign]), 422, 'validation_failed');

        $id = DB::table('parties')->where('name', 'Ok 5101')->value('id');
        $this->req('PATCH', "/api/v1/parties/{$id}", ['default_purchase_ledger_id' => $this->ledger('6105')], ['If-Match' => 'W/"1"'])
            ->assertOk()->assertJsonPath('data.default_purchase_ledger_id', $this->ledger('6105'));
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['default_purchase_ledger_id' => $this->ledger('5001')], ['If-Match' => 'W/"2"']), 422, 'validation_failed');
        $this->assertSame(2, DB::table('parties')->count(), 'failed requests created nothing');
    }

    public function test_list_filters_by_status_balance_search_area_country_and_sorts(): void
    {
        $this->req('POST', '/api/v1/parties', self::BENGAL)->assertCreated();
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Padma Flour Mills', 'mobile' => '01812000111', 'area' => 'Narayanganj'])->assertCreated();
        $this->req('POST', '/api/v1/parties', self::GUANGZHOU)->assertCreated();
        $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230', 'area' => 'Tejgaon'])->assertCreated();
        $padma = DB::table('parties')->where('name', 'Padma Flour Mills')->value('id');
        $this->req('POST', "/api/v1/parties/{$padma}/deactivate")->assertOk();
        $bengal = DB::table('parties')->where('name', 'Bengal Edible Oils Ltd.')->first();
        $this->postPurchase($bengal->ledger_id, '75000.00');

        $this->req('GET', '/api/v1/parties?type=supplier&sort=name')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Bengal Edible Oils Ltd.');
        $this->req('GET', '/api/v1/parties?type=supplier&status=inactive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Padma Flour Mills');
        $this->req('GET', '/api/v1/parties?type=supplier&balance=outstanding')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'SUP-0001');
        $this->req('GET', '/api/v1/parties?type=supplier&balance=zero')->assertJsonPath('meta.total', 2);
        $this->req('GET', '/api/v1/parties?type=supplier&q=SUP-0002')->assertJsonPath('data.0.name', 'Padma Flour Mills');
        $this->req('GET', '/api/v1/parties?type=supplier&q=Nasir')->assertJsonPath('meta.total', 1);
        $this->req('GET', '/api/v1/parties?type=supplier&area=Tejgaon')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.party_type', 'supplier');
        $this->req('GET', '/api/v1/parties?type=supplier&country_code=CN')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Guangzhou Packaging Co.');
        $this->req('GET', '/api/v1/parties?type=supplier&country_code=cn')->assertJsonPath('meta.total', 1);
        $this->req('GET', '/api/v1/parties?type=supplier&country_code=BD')->assertJsonPath('meta.total', 2);
        // High to low payable puts the supplier we owe most first, low to high last.
        $this->req('GET', '/api/v1/parties?type=supplier&sort=-balance')->assertJsonPath('data.0.code', 'SUP-0001');
        $this->req('GET', '/api/v1/parties?type=supplier&sort=balance')->assertJsonPath('data.2.code', 'SUP-0001');
        $this->assertProblem($this->req('GET', '/api/v1/parties?type=supplier&country_code=CHN'), 422, 'validation_failed');
    }

    public function test_payable_balance_is_a_credit_shown_positive_and_history_blocks_delete(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::BENGAL)->assertCreated()->json('data.id');
        $party = DB::table('parties')->find($id);
        $this->postPurchase($party->ledger_id, '75000.00');

        $this->req('GET', "/api/v1/parties/{$id}")->assertOk()->assertJsonPath('data.balance', '75000.00')
            ->assertJsonPath('data.balance_side', 'cr')->assertJsonPath('data.opening_locked', true);
        $this->assertProblem($this->req('DELETE', "/api/v1/parties/{$id}"), 409, 'has_history')
            ->assertJsonPath('detail', 'This supplier has transactions; mark it inactive instead.');
        $this->req('POST', "/api/v1/parties/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_edit_requires_if_match_detects_conflicts_and_keeps_the_type(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::BENGAL)->assertCreated()->json('data.id');
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Bengal Oils']), 428, 'if_match_required');

        $ok = $this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Bengal Edible Oils & Fats Ltd.', 'port_of_loading' => 'Chattogram'], ['If-Match' => 'W/"1"'])->assertOk();
        $ok->assertJsonPath('data.version', 2)->assertJsonPath('data.port_of_loading', 'Chattogram');
        $this->assertSame('Bengal Edible Oils & Fats Ltd.', DB::table('ledgers')->where('id', $ok->json('data.ledger_id'))->value('name'));

        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Stale'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')
            ->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['party_type' => 'customer'], ['If-Match' => 'W/"2"']), 422, 'validation_failed');

        // Changing an international supplier keeps its own mobile format (stored country decides, R-33).
        $cn = $this->req('POST', '/api/v1/parties', self::GUANGZHOU)->assertCreated()->json('data.id');
        $this->req('PATCH', "/api/v1/parties/{$cn}", ['mobile' => '+86 20 3888 9999'], ['If-Match' => 'W/"1"'])->assertOk();
    }

    public function test_deactivate_reactivate_and_delete_without_history(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::BENGAL)->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/parties/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->req('POST', "/api/v1/parties/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->req('DELETE', "/api/v1/parties/{$id}")->assertNoContent();
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$id}"), 404, 'not_found');
        $this->assertSame(0, (int) DB::table('ledgers')->where('id', DB::table('parties')->where('id', $id)->value('ledger_id'))->value('is_active'));
        $this->assertSame(['deactivated', 'reactivated', 'deleted'], AuditLog::where('auditable_id', $id)->where('auditable_type', 'parties')
            ->whereIn('action', ['deactivated', 'reactivated', 'deleted'])->orderBy('id')->pluck('action')->all());
    }

    public function test_duplicate_supplier_is_a_warning_and_customers_do_not_count(): void
    {
        $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Bengal Edible Oils Ltd.', 'mobile' => '01700000001'])->assertCreated();
        $this->req('POST', '/api/v1/parties', self::BENGAL)->assertCreated()->assertJsonMissingPath('warnings.0');
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Oils (new depot)', 'mobile' => '01911222333'])
            ->assertCreated()->assertJsonPath('warnings.0.code', 'DUPLICATE_PARTY')->assertJsonPath('data.code', 'SUP-0002');
    }

    public function test_idempotency_key_replays_the_same_supplier(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/parties', self::GUANGZHOU, ['Idempotency-Key' => $key])->assertCreated();
        $b = $this->req('POST', '/api/v1/parties', self::GUANGZHOU, ['Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame('true', $b->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, DB::table('parties')->count());
    }

    /** A posted purchase on the supplier's ledger (Dr purchases / Cr supplier), as the posting engine will write it. */
    private function postPurchase(int $ledgerId, string $amount): void
    {
        $cid = $this->company->id;
        $fy = DB::table('fiscal_years')->where('company_id', $cid)->value('id');
        $period = DB::table('accounting_periods')->where('company_id', $cid)->where('status', 'open')->orderBy('start_date')->first();
        $je = DB::table('journal_entries')->insertGetId(['company_id' => $cid, 'fiscal_year_id' => $fy, 'accounting_period_id' => $period->id,
            'entry_type' => 'purchase_bill', 'voucher_number' => 'BILL-TEST-0001', 'entry_date' => $period->start_date, 'status' => 'posted',
            'total_debit' => $amount, 'total_credit' => $amount, 'is_system_generated' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, $this->ledger('5101'), $amount, '0.00'], [2, $ledgerId, '0.00', $amount]] as [$no, $ledger, $dr, $cr]) {
            DB::table('journal_lines')->insert(['company_id' => $cid, 'journal_entry_id' => $je, 'line_no' => $no, 'ledger_id' => $ledger,
                'debit' => $dr, 'credit' => $cr, 'entry_date' => $period->start_date, 'is_posted' => true]);
        }
    }
}
