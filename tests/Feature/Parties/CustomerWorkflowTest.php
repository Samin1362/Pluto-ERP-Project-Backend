<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** B1 list, B2 add/edit, B3 details, deactivate/reactivate/delete (05 Parties; BR-006, BR-026, BR-037, BR-049, BR-050, BR-063). */
class CustomerWorkflowTest extends TestCase
{
    private Company $company;

    private string $token;

    private const RAHMAN = ['party_type' => 'customer', 'name' => 'Rahman Stores', 'category' => 'business', 'contact_person' => 'Abdur Rahman',
        'mobile' => '01711458230', 'email' => 'rahman.stores@example.com', 'address' => '14 Moulvibazar Road', 'district' => 'Dhaka',
        'area' => 'Old Dhaka', 'credit_limit' => '500000.00', 'price_tier' => 'wholesale'];

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

    public function test_create_customer_gets_code_ledger_location_etag_and_audit(): void
    {
        $r = $this->req('POST', '/api/v1/parties', self::RAHMAN)->assertCreated();
        $r->assertJsonPath('data.code', 'CUS-0001')->assertJsonPath('data.party_type', 'customer')
            ->assertJsonPath('data.mobile', '01711-458230')->assertJsonPath('data.ledger_code', '1201-001')
            ->assertJsonPath('data.credit_limit', '500000.00')->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.balance_side', 'dr')->assertJsonPath('data.overdue', '0.00')
            ->assertJsonPath('data.country_code', 'BD')->assertJsonPath('data.default_currency_code', 'BDT')
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.opening_locked', false)->assertJsonPath('data.version', 1);
        $id = $r->json('data.id');
        $this->assertSame(url('/api/v1/parties/'.$id), $r->headers->get('Location'));
        $this->assertSame('W/"1"', $r->headers->get('ETag'));

        $ledger = DB::table('ledgers')->where('id', $r->json('data.ledger_id'))->first();
        $group = DB::table('account_groups')->where('id', $ledger->account_group_id)->first();
        $this->assertSame(['Rahman Stores', 'party', 'Sundry debtors'], [$ledger->name, $ledger->ledger_kind, $group->name]);
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'parties')->where('auditable_id', $id)->exists());

        $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Karim Traders', 'mobile' => '01812-000111'])
            ->assertCreated()->assertJsonPath('data.code', 'CUS-0002')->assertJsonPath('data.ledger_code', '1201-002');
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911-222333'])
            ->assertCreated()->assertJsonPath('data.code', 'SUP-0001')->assertJsonPath('data.ledger_code', '2101-001');
    }

    public function test_validation_failures_use_field_errors_and_catalogue_codes(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer']), 422, 'validation_failed');
        $this->assertSame(['Enter the customer or supplier name'], $r->json('errors.name'));
        $this->assertSame(['Enter a mobile number'], $r->json('errors.mobile'));

        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'X', 'mobile' => '0171145823']), 422, 'invalid_mobile')
            ->assertJsonPath('errors.mobile.0', 'Mobile numbers have 11 digits starting 01');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'X', 'mobile' => '01711458230', 'credit_limit' => 5000]), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['credit_limit']]);
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'X', 'mobile' => '01711458230', 'nickname' => 'x']), 422, 'validation_failed')
            ->assertJsonPath('errors.nickname.0', 'This field is not part of the API.');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'X', 'mobile' => '01711458230', 'opening_balance' => '1500.123']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['opening_balance']]); // openings are allowed since P6.1-BE-06; the shape is still checked

        // An international supplier has no Bangladeshi mobile (R-33).
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Guangzhou Packaging Co.', 'country_code' => 'cn', 'default_currency_code' => 'CNY'])
            ->assertCreated()->assertJsonPath('data.country_code', 'CN')->assertJsonPath('data.mobile', null);
        $this->assertSame(1, DB::table('parties')->count(), 'failed requests created nothing');
    }

    public function test_references_from_another_company_are_rejected(): void
    {
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $foreignTerm = DB::table('payment_terms')->where('company_id', $other->id)->value('id');
        $this->assertProblem($this->req('POST', '/api/v1/parties', self::RAHMAN + ['payment_term_id' => $foreignTerm]), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['payment_term_id']]);

        $padma = $this->login('padma@agro.example');
        $foreignParty = $this->api('POST', '/api/v1/parties', $padma, $other->id, self::RAHMAN)->assertCreated()->json('data.id');
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$foreignParty}"), 404, 'not_found');
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$foreignParty}", ['name' => 'Hijack'], ['If-Match' => 'W/"1"']), 404, 'not_found');
        $this->req('GET', '/api/v1/parties?type=customer')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_list_filters_searches_sorts_and_paginates(): void
    {
        foreach (['Rahman Stores' => '01711458230', 'Karim Traders' => '01812000111', 'Bhai Bhai Enterprise' => '01911000222'] as $name => $mobile) {
            $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => $name, 'mobile' => $mobile, 'area' => 'Old Dhaka'])->assertCreated();
        }
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333'])->assertCreated();
        $karim = DB::table('parties')->where('name', 'Karim Traders')->value('id');
        $this->req('POST', "/api/v1/parties/{$karim}/deactivate")->assertOk();

        $all = $this->req('GET', '/api/v1/parties?type=customer&per_page=2&sort=name')->assertOk();
        $all->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.name', 'Bhai Bhai Enterprise');
        $this->req('GET', '/api/v1/parties?type=customer&status=active')->assertJsonPath('meta.total', 2);
        $this->req('GET', '/api/v1/parties?type=customer&q=01812')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Karim Traders');
        $this->req('GET', '/api/v1/parties?type=customer&q=CUS-0003')->assertJsonPath('data.0.name', 'Bhai Bhai Enterprise');
        $this->req('GET', '/api/v1/parties?type=customer&sort=-code')->assertJsonPath('data.0.code', 'CUS-0003');
        $this->req('GET', '/api/v1/parties?type=supplier')->assertJsonPath('meta.total', 1);
        $this->req('GET', '/api/v1/parties?type=customer&balance=outstanding')->assertJsonPath('meta.total', 0);

        $this->assertProblem($this->req('GET', '/api/v1/parties'), 422, 'validation_failed')->assertJsonStructure(['errors' => ['type']]);
        $this->assertProblem($this->req('GET', '/api/v1/parties?type=customer&colour=red'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/parties?type=customer&per_page=500'), 422, 'validation_failed');
    }

    public function test_balance_comes_from_posted_ledger_lines_and_history_blocks_delete(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::RAHMAN)->assertCreated()->json('data.id');
        $party = DB::table('parties')->find($id);
        $this->postEntry($party->ledger_id, '118450.00');

        $this->req('GET', "/api/v1/parties/{$id}")->assertOk()->assertJsonPath('data.balance', '118450.00')
            ->assertJsonPath('data.balance_side', 'dr')->assertJsonPath('data.opening_locked', true);
        $this->req('GET', '/api/v1/parties?type=customer&balance=outstanding')->assertJsonPath('meta.total', 1);
        $this->assertProblem($this->req('DELETE', "/api/v1/parties/{$id}"), 409, 'has_history');
        $this->req('POST', "/api/v1/parties/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_edit_requires_if_match_and_detects_conflicts(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::RAHMAN)->assertCreated()->json('data.id');
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Rahman Stores & Sons']), 428, 'if_match_required');

        $ok = $this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Rahman Stores & Sons', 'credit_limit' => '600000.00'], ['If-Match' => 'W/"1"'])->assertOk();
        $ok->assertJsonPath('data.version', 2)->assertJsonPath('data.credit_limit', '600000.00');
        $this->assertSame('W/"2"', $ok->headers->get('ETag'));
        $this->assertSame('Rahman Stores & Sons', DB::table('ledgers')->where('id', $ok->json('data.ledger_id'))->value('name'), 'ledger renamed with the party');

        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['name' => 'Stale edit'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')
            ->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', "/api/v1/parties/{$id}", ['party_type' => 'supplier'], ['If-Match' => 'W/"2"']), 422, 'validation_failed');
        $audit = AuditLog::where('action', 'updated')->where('auditable_id', $id)->firstOrFail();
        $this->assertSame('Rahman Stores', $audit->old_values['name']);
        $this->assertSame('Rahman Stores & Sons', $audit->new_values['name']);
    }

    public function test_deactivate_reactivate_and_delete_without_history(): void
    {
        $id = $this->req('POST', '/api/v1/parties', self::RAHMAN)->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/parties/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->req('POST', "/api/v1/parties/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->req('DELETE', "/api/v1/parties/{$id}")->assertNoContent();
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$id}"), 404, 'not_found');
        $this->assertNotNull(DB::table('parties')->where('id', $id)->value('deleted_at'), 'master data is soft-deleted');
        $this->assertSame(['deactivated', 'reactivated', 'deleted'], AuditLog::where('auditable_id', $id)->where('auditable_type', 'parties')
            ->whereIn('action', ['deactivated', 'reactivated', 'deleted'])->orderBy('id')->pluck('action')->all());
    }

    public function test_duplicate_name_is_a_warning_not_an_error(): void
    {
        $this->req('POST', '/api/v1/parties', self::RAHMAN)->assertCreated();
        $r = $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01700000000'])->assertCreated();
        $r->assertJsonPath('warnings.0.code', 'DUPLICATE_PARTY')->assertJsonPath('data.code', 'CUS-0002');
    }

    public function test_idempotency_key_replays_and_rejects_reuse_with_another_body(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/parties', self::RAHMAN, ['Idempotency-Key' => $key])->assertCreated();
        $b = $this->req('POST', '/api/v1/parties', self::RAHMAN, ['Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame('true', $b->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, DB::table('parties')->count());
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Other', 'mobile' => '01711000000'], ['Idempotency-Key' => $key]), 422, 'idempotency_key_reused');
    }

    public function test_lookups_for_the_form(): void
    {
        $terms = $this->req('GET', '/api/v1/payment-terms')->assertOk();
        $this->assertSame(['Cash', 'Net 7', 'Net 14', 'Net 15', 'Net 30', 'Net 45'], array_column($terms->json('data'), 'name'));
        $this->assertSame('Net 15', collect($terms->json('data'))->firstWhere('is_default', true)['name']);
        $this->req('GET', '/api/v1/party-groups?type=customer')->assertOk()->assertJsonPath('meta.total', 0);
    }

    /** A posted sale on the customer's ledger (Dr customer / Cr sales), as the posting engine will write it. */
    private function postEntry(int $ledgerId, string $amount): void
    {
        $cid = $this->company->id;
        $fy = DB::table('fiscal_years')->where('company_id', $cid)->value('id');
        $period = DB::table('accounting_periods')->where('company_id', $cid)->where('status', 'open')->orderBy('start_date')->first();
        $sales = DB::table('ledgers')->where('company_id', $cid)->where('code', '4101')->value('id');
        $je = DB::table('journal_entries')->insertGetId(['company_id' => $cid, 'fiscal_year_id' => $fy, 'accounting_period_id' => $period->id,
            'entry_type' => 'sales_invoice', 'voucher_number' => 'INV-TEST-0001', 'entry_date' => $period->start_date, 'status' => 'posted',
            'total_debit' => $amount, 'total_credit' => $amount, 'is_system_generated' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, $ledgerId, $amount, '0.00'], [2, $sales, '0.00', $amount]] as [$no, $ledger, $dr, $cr]) {
            DB::table('journal_lines')->insert(['company_id' => $cid, 'journal_entry_id' => $je, 'line_no' => $no, 'ledger_id' => $ledger,
                'debit' => $dr, 'credit' => $cr, 'entry_date' => $period->start_date, 'is_posted' => true]);
        }
    }
}
