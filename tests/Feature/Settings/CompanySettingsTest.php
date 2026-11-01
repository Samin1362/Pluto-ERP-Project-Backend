<?php

namespace Tests\Feature\Settings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * POST /companies, /company, /company/logo, /branches, PATCH /company/settings, /number-sequences, /tax-rules
 * (K1–K7, S0; BR-035, BR-036, BR-006; GAP-08; TC-ACC-017; backlog P15.1-BE-01..03).
 */
class CompanySettingsTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        $h = $headers + (in_array($method, ['POST'], true) ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $h);
        $this->useBooks();

        return $r;
    }

    public function test_s0_a_user_creates_a_company_and_becomes_its_owner(): void
    {
        $key = (string) Str::uuid();
        $body = ['legal_name' => 'Padma Agro Ltd.', 'trading_name' => 'Padma Agro', 'business_type' => 'partnership', 'fy_start_month' => 1];
        $r = $this->api('POST', '/api/v1/companies', $this->token, null, $body, ['Idempotency-Key' => $key])->assertCreated();
        $r->assertJsonPath('data.legal_name', 'Padma Agro Ltd.')->assertJsonPath('data.business_type', 'partnership')->assertJsonPath('data.fy_start_month', 1)
            ->assertJsonPath('data.base_currency_code', 'BDT')->assertJsonPath('data.branches.0.code', 'HO')->assertJsonPath('data.seats_used', 1);
        $id = $r->json('data.id');
        $this->api('POST', '/api/v1/companies', $this->token, null, $body, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('companies')->where('legal_name', 'Padma Agro Ltd.')->count(), 'replayed, not created twice');
        $fy = DB::table('fiscal_years')->where('company_id', $id)->first();
        $this->assertSame('01-01', substr($fy->start_date, 5, 5), 'January financial year');
        $me = $this->api('GET', '/api/v1/me', $this->token)->json('data.companies');
        $this->assertSame('Owner', collect($me)->firstWhere('company_id', $id)['role']);
        $this->assertProblem($this->api('POST', '/api/v1/companies', $this->token, null, ['legal_name' => 'X'], ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed')
            ->assertJsonPath('errors.business_type.0', 'Choose the type of business');
    }

    public function test_k1_k2_profile_logo_and_locations(): void
    {
        $r = $this->req('GET', '/api/v1/company')->assertOk()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.seat_limit', 10)->assertJsonPath('data.logo_url', null)->assertJsonPath('data.timezone', 'Asia/Dhaka');
        $this->assertProblem($this->req('PATCH', '/api/v1/company', ['phone' => '01711000000']), 428, 'if_match_required');
        $this->req('PATCH', '/api/v1/company', ['trading_name' => 'Meghna', 'tax_registration_no' => '001234567-0101', 'email' => 'accounts@meghna.example'], ['If-Match' => 'W/"1"'])
            ->assertOk()->assertJsonPath('data.trading_name', 'Meghna')->assertJsonPath('data.version', 2);
        $this->assertProblem($this->req('PATCH', '/api/v1/company', ['phone' => '1'], ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $this->assertProblem($this->req('PATCH', '/api/v1/company', ['base_currency_code' => 'USD'], ['If-Match' => 'W/"2"']), 422, 'validation_failed');

        $png = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $up = $this->call('PUT', '/api/v1/company/logo', [], [], ['file' => $png], $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}",
            'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json', 'If-Match' => 'W/"2"']))->assertOk();
        $url = $up->json('data.logo_url');
        $this->assertStringStartsWith("/api/v1/company/{$this->books->id}/logo/file", $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $this->assertProblem($this->call('PUT', '/api/v1/company/logo', [], [], ['file' => $svg], $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}",
            'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json', 'If-Match' => 'W/"3"'])), 415, 'file_type_not_allowed');
        $this->req('DELETE', '/api/v1/company/logo')->assertNoContent();
        $this->req('GET', '/api/v1/company')->assertJsonPath('data.logo_url', null);

        $depot = $this->req('POST', '/api/v1/branches', ['code' => 'CTG', 'name' => 'Chattogram depot', 'kind' => 'Warehouse'])->assertCreated();
        $this->assertProblem($this->req('POST', '/api/v1/branches', ['code' => 'CTG', 'name' => 'Again']), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Another location uses this code');
        $this->req('PATCH', '/api/v1/branches/'.$depot->json('data.id'), ['is_head_office' => true], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.is_head_office', true);
        $this->assertSame(1, DB::table('branches')->where('company_id', $this->books->id)->where('is_head_office', true)->count(), 'one head office');
        $this->assertProblem($this->req('PATCH', '/api/v1/branches/'.$depot->json('data.id'), ['is_active' => false], ['If-Match' => 'W/"2"']), 422, 'validation_failed');

        $this->userWithRole('accountant');
        $acc = $this->login('accountant@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/company', token: $acc), 403, 'permission_denied');
    }

    public function test_k3_k5_k7_settings_and_the_owner_only_switches(): void
    {
        $s = $this->req('GET', '/api/v1/company/settings')->json('data');
        $v = $s['version'];
        $r = $this->req('PATCH', '/api/v1/company/settings', ['invoice_rounding' => 'nearest_5', 'number_grouping' => 'million', 'invoice' => ['template' => 'banded',
            'accent_color' => '#1A4D8F', 'footer_note' => 'Thank you for your business']], ['If-Match' => "W/\"{$v}\""])->assertOk();
        $r->assertJsonPath('data.invoice_rounding', 'nearest_5')->assertJsonPath('data.invoice.template', 'banded')->assertJsonPath('data.invoice.accent_color', '#1A4D8F')
            ->assertJsonPath('data.number_grouping', 'million')->assertJsonPath('data.version', $v + 1);
        $this->assertProblem($this->req('PATCH', '/api/v1/company/settings', ['invoice' => ['colour' => 'red']], ['If-Match' => 'W/"'.($v + 1).'"']), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', '/api/v1/company/settings', ['valuation_method' => 'fifo'], ['If-Match' => 'W/"'.($v + 1).'"']), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', '/api/v1/company/settings', ['require_two_factor' => true], ['If-Match' => 'W/"'.($v + 1).'"']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['require_two_factor']]);
        $this->req('PATCH', '/api/v1/company/settings', ['idle_timeout_minutes' => 30, 'backup_retention_days' => 60], ['If-Match' => 'W/"'.($v + 1).'"'])->assertOk()
            ->assertJsonPath('data.idle_timeout_minutes', 30)->assertJsonPath('data.backup_retention_days', 60);

        $this->userWithRole('finance');
        $fin = $this->login('finance@meghna.example');
        $this->assertProblem($this->req('PATCH', '/api/v1/company/settings', ['backup_enabled' => false], ['If-Match' => 'W/"'.($v + 2).'"'], $fin), 403, 'permission_denied');
        $this->req('PATCH', '/api/v1/company/settings', ['date_format' => 'd/m/Y'], ['If-Match' => 'W/"'.($v + 2).'"'], $fin)->assertOk();
    }

    public function test_t_c_acc_017_number_series_never_go_back(): void
    {
        $rows = collect($this->req('GET', '/api/v1/number-sequences')->assertOk()->json('data'))->keyBy('document_type');
        $this->assertTrue($rows->has('customer') && $rows->has('sales_invoice') && $rows->has('journal'), 'every series of the year and the never-reset ones (GAP-08)');
        $inv = $rows['sales_invoice'];
        $this->assertSame($inv['prefix'].str_pad('1', $inv['padding'], '0', STR_PAD_LEFT), $inv['preview']);
        $r = $this->req('PATCH', "/api/v1/number-sequences/{$inv['id']}", ['prefix' => 'MT-INV-', 'next_number' => 501, 'padding' => 5], ['If-Match' => "W/\"{$inv['version']}\""])->assertOk();
        $r->assertJsonPath('data.preview', 'MT-INV-00501');
        $this->assertProblem($this->req('PATCH', "/api/v1/number-sequences/{$inv['id']}", ['next_number' => 100], ['If-Match' => 'W/"'.($inv['version'] + 1).'"']), 422, 'validation_failed')
            ->assertJsonPath('errors.next_number.0', 'Numbers up to 500 are already used; the next can only go up');
        $this->assertProblem($this->req('PATCH', "/api/v1/number-sequences/{$inv['id']}", ['prefix' => 'INV 2026'], ['If-Match' => 'W/"'.($inv['version'] + 1).'"']), 422, 'validation_failed');
    }

    public function test_k6_tax_rules_take_the_users_rate_and_a_liability_or_asset_ledger(): void
    {
        $vat = $this->req('POST', '/api/v1/tax-rules', ['name' => 'VAT 15%', 'rate' => '15', 'applies_to' => 'sales', 'create_ledger' => true])->assertCreated();
        $vat->assertJsonPath('data.rate', '15.0000')->assertJsonPath('data.ledger_name', 'VAT 15% payable')->assertJsonPath('data.is_active', true);
        $this->assertSame('21', DB::table('account_groups')->where('id', DB::table('ledgers')->where('id', $vat->json('data.ledger_id'))->value('account_group_id'))->value('code'));
        $sales = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '4101')->value('id');
        $this->assertProblem($this->req('POST', '/api/v1/tax-rules', ['name' => 'AIT', 'rate' => '5', 'applies_to' => 'purchases', 'ledger_id' => $sales]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/tax-rules', ['name' => 'AIT', 'rate' => '101', 'applies_to' => 'purchases', 'create_ledger' => true]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/tax-rules', ['name' => 'AIT', 'rate' => '5', 'applies_to' => 'purchases']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['ledger_id']]);
        $this->assertProblem($this->req('POST', '/api/v1/tax-rules', ['name' => 'VAT 15%', 'rate' => '15', 'applies_to' => 'sales', 'create_ledger' => true]), 422, 'validation_failed');
        $id = $vat->json('data.id');
        $this->req('PATCH', "/api/v1/tax-rules/{$id}", ['rate' => '7.5', 'is_active' => false], ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.rate', '7.5000')->assertJsonPath('data.is_active', false);
        $this->assertCount(1, $this->req('GET', '/api/v1/tax-rules')->json('data'));
    }
}
