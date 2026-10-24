<?php

namespace Tests\Feature\Contract;

use App\Domain\Access\Totp;
use App\Models\Company;
use Database\Seeders\DemoTaxRuleSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Real responses of the slice's endpoints validated against docs/openapi.yaml (the approved contract, 05):
 * the status must be declared for the operation, the body must match the declared schema (JSON Schema 2020-12),
 * and `data` objects may not carry fields the contract does not declare. Partial TC-CON-006.
 */
class OpenApiConformanceTest extends TestCase
{
    private const DOC_URI = 'https://contract.pluto.example/openapi.json';

    private static ?object $doc = null;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
    }

    public function test_auth_endpoints_match_the_contract(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => self::PASSWORD]);
        $this->assertConforms($login, 'POST', '/auth/login');
        $rt = $this->refreshCookie($login)->getValue();
        $token = $login->json('data.access_token');

        $this->assertConforms($this->api('GET', '/api/v1/me', $token), 'GET', '/me');
        $this->assertConforms($this->api('GET', '/api/v1/me', $token, $this->company->id), 'GET', '/me');
        $this->assertConforms($this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => 'wrong password!']), 'POST', '/auth/login');
        $this->assertConforms($this->postJson('/api/v1/auth/login', ['email' => 'not-an-email']), 'POST', '/auth/login');
        $refresh = $this->withUnencryptedCookie('pl_rt', $rt)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson('/api/v1/auth/refresh');
        $this->assertConforms($refresh, 'POST', '/auth/refresh');
        $this->assertConforms($this->withUnencryptedCookie('pl_rt', 'bogus')->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson('/api/v1/auth/refresh'), 'POST', '/auth/refresh');
        $this->assertConforms($this->getJson('/api/v1/me'), 'GET', '/me');
    }

    public function test_customer_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $created = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230',
            'payment_term_id' => \DB::table('payment_terms')->where('company_id', $c)->where('name', 'Net 15')->value('id'), 'credit_limit' => '500000.00',
            'bank_accounts' => [['bank_name' => 'Sonali Bank', 'account_number' => '0123456789', 'is_default' => true]]]);
        $this->assertConforms($created, 'POST', '/parties');
        $id = $created->json('data.id');

        $this->assertConforms($this->api('GET', '/api/v1/parties?type=customer', $t, $c), 'GET', '/parties');
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}", $t, $c), 'GET', '/parties/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/parties/{$id}", $t, $c, ['notes' => 'Prefers morning delivery'], ['If-Match' => 'W/"1"']), 'PATCH', '/parties/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/parties/{$id}", $t, $c, ['notes' => 'x']), 'PATCH', '/parties/{id}');            // 428
        $this->assertConforms($this->api('PATCH', "/api/v1/parties/{$id}", $t, $c, ['notes' => 'x'], ['If-Match' => 'W/"1"']), 'PATCH', '/parties/{id}'); // 409
        $this->assertConforms($this->api('POST', "/api/v1/parties/{$id}/deactivate", $t, $c), 'POST', '/parties/{id}/deactivate');
        $this->assertConforms($this->api('POST', "/api/v1/parties/{$id}/reactivate", $t, $c), 'POST', '/parties/{id}/reactivate');
        $this->assertConforms($this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'X', 'mobile' => '123']), 'POST', '/parties'); // 422
        $this->assertConforms($this->api('GET', '/api/v1/parties/999999', $t, $c), 'GET', '/parties/{id}');                                  // 404
        $this->assertConforms($this->api('GET', '/api/v1/parties?type=customer', $t), 'GET', '/parties');                                     // 400
        $this->assertConforms($this->api('GET', '/api/v1/payment-terms', $t, $c), 'GET', '/payment-terms');
        $this->assertConforms($this->api('GET', '/api/v1/party-groups?type=customer', $t, $c), 'GET', '/party-groups');
        $this->assertConforms($this->api('POST', '/api/v1/party-groups', $t, $c, ['party_type' => 'customer', 'name' => 'Wholesale']), 'POST', '/party-groups');
        $this->assertConforms($this->api('POST', '/api/v1/party-groups', $t, $c, ['party_type' => 'customer', 'name' => 'Wholesale']), 'POST', '/party-groups'); // 422
        $this->assertConforms($this->api('POST', '/api/v1/parties/bulk-deactivate', $t, $c, ['ids' => [$id, 999999]]), 'POST', '/parties/bulk-deactivate');
        $this->assertConforms($this->api('POST', '/api/v1/parties/bulk-deactivate', $t, $c, ['ids' => []]), 'POST', '/parties/bulk-deactivate'); // 422
        $this->assertConforms($this->api('DELETE', "/api/v1/parties/{$id}", $t, $c), 'DELETE', '/parties/{id}');

        $store = $this->login('store@meghna.example');
        $this->assertConforms($this->api('GET', '/api/v1/parties?type=customer', $store, $c), 'GET', '/parties');                             // 403
    }

    public function test_platform_lookups_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $this->assertConforms($this->api('GET', '/api/v1/fiscal-years', $t, $c), 'GET', '/fiscal-years');
        $this->assertConforms($this->api('GET', '/api/v1/branches?q=head&sort=-name&per_page=10', $t, $c), 'GET', '/branches');
        $this->assertConforms($this->api('GET', '/api/v1/company/settings', $t, $c), 'GET', '/company/settings');
        $this->assertConforms($this->api('GET', '/api/v1/branches?status=all', $t, $c), 'GET', '/branches');          // 422
        $this->assertConforms($this->api('GET', '/api/v1/fiscal-years', $t), 'GET', '/fiscal-years');                 // 400

        $store = $this->login('store@meghna.example');
        $this->assertConforms($this->api('GET', '/api/v1/company/settings', $store, $c), 'GET', '/company/settings');  // without admin fields

        $party = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'])->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$party}", $t, $c), 'GET', '/audit-logs');
        $this->assertConforms($this->api('GET', '/api/v1/audit-logs?auditable_type=nope&auditable_id=1', $t, $c), 'GET', '/audit-logs');   // 422
        $this->assertConforms($this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$party}", $store, $c), 'GET', '/audit-logs'); // 403

        $this->assertConforms($this->api('GET', '/api/v1/ledgers?per_page=100', $t, $c), 'GET', '/ledgers');                                   // chart with balances
        $this->assertConforms($this->api('GET', '/api/v1/ledgers?purpose=tax', $store, $c), 'GET', '/ledgers');                                // picker, no balances
        $this->assertConforms($this->api('GET', '/api/v1/ledgers', $store, $c), 'GET', '/ledgers');                                            // 422 purpose_required
    }

    public function test_inventory_lookups_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $this->seed(DemoTaxRuleSeeder::class);
        $this->assertConforms($this->api('GET', '/api/v1/units?q=k&sort=-name&per_page=5', $t, $c), 'GET', '/units');
        $this->assertConforms($this->api('POST', '/api/v1/units', $t, $c, ['name' => 'ream', 'decimals' => 0]), 'POST', '/units');
        $this->assertConforms($this->api('POST', '/api/v1/units', $t, $c, ['name' => 'ream']), 'POST', '/units');                 // 422
        $this->assertConforms($this->api('GET', '/api/v1/tax-rules', $this->login('store@meghna.example'), $c), 'GET', '/tax-rules');

        foreach (['/product-categories', '/stock-groups'] as $path) {
            $top = $this->api('POST', "/api/v1{$path}", $t, $c, ['name' => 'Top', 'sort_order' => 1] + ($path === '/product-categories' ? ['code_prefix' => 'TOP'] : []));
            $this->assertConforms($top, 'POST', $path);
            $id = $top->json('data.id');
            $this->assertConforms($this->api('POST', "/api/v1{$path}", $t, $c, ['name' => 'Child', 'parent_id' => $id]), 'POST', $path);
            $this->assertConforms($this->api('POST', "/api/v1{$path}", $t, $c, ['name' => 'Top']), 'POST', $path);                   // 422
            $this->assertConforms($this->api('GET', "/api/v1{$path}?per_page=10", $t, $c), 'GET', $path);
            $this->assertConforms($this->api('GET', "/api/v1{$path}", $this->login('store@meghna.example'), $c), 'GET', $path);   // no stock_value
            $this->assertConforms($this->api('PATCH', "/api/v1{$path}/{$id}", $t, $c, ['name' => 'Top level']), 'PATCH', "{$path}/{id}");
            $this->assertConforms($this->api('DELETE', "/api/v1{$path}/{$id}", $t, $c), 'DELETE', "{$path}/{id}");                 // 409 has_history
            $this->assertConforms($this->api('DELETE', "/api/v1{$path}/999999", $t, $c), 'DELETE', "{$path}/{id}");                // 404
        }
    }

    public function test_warehouse_and_product_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $store = $this->login('store@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $branch = \DB::table('branches')->where('company_id', $c)->value('id');

        $wh = $this->api('POST', '/api/v1/warehouses', $t, $c, ['code' => 'WH-TEJ', 'name' => 'Tejgaon warehouse', 'branch_id' => $branch, 'opens_on' => '2026-07-01'], $key());
        $this->assertConforms($wh, 'POST', '/warehouses');
        $w = $wh->json('data.id');
        $this->assertConforms($this->api('POST', '/api/v1/warehouses', $t, $c, ['code' => 'WH-TEJ', 'name' => 'Again', 'branch_id' => $branch], $key()), 'POST', '/warehouses'); // 422
        $this->assertConforms($this->api('GET', '/api/v1/warehouses', $t, $c), 'GET', '/warehouses');
        $this->assertConforms($this->api('GET', '/api/v1/warehouses', $store, $c), 'GET', '/warehouses');                             // no stock_value
        $this->assertConforms($this->api('GET', "/api/v1/warehouses/{$w}", $t, $c), 'GET', '/warehouses/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/warehouses/999999', $t, $c), 'GET', '/warehouses/{id}');                    // 404
        $this->assertConforms($this->api('PATCH', "/api/v1/warehouses/{$w}", $t, $c, ['phone' => '01711000111'], ['If-Match' => 'W/"1"']), 'PATCH', '/warehouses/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/warehouses/{$w}", $t, $c, ['phone' => '1'], ['If-Match' => 'W/"1"']), 'PATCH', '/warehouses/{id}'); // 409
        $this->assertConforms($this->api('PATCH', "/api/v1/warehouses/{$w}", $t, $c, ['phone' => '1']), 'PATCH', '/warehouses/{id}');  // 428

        $unit = \DB::table('units')->where('company_id', $c)->where('name', 'jar')->value('id');
        $income = \DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id');
        $p = $this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'product', 'name' => 'Soybean oil 5L', 'unit_id' => $unit, 'selling_price' => '920.00',
            'income_ledger_id' => $income, 'last_purchase_cost' => '845.0000', 'warehouses' => [['warehouse_id' => $w, 'reorder_level' => '20', 'reorder_qty' => '50']]], $key());
        $this->assertConforms($p, 'POST', '/products');
        $id = $p->json('data.id');
        $this->assertConforms($this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'service', 'name' => 'Delivery', 'unit_id' => $unit, 'selling_price' => '50.00',
            'income_ledger_id' => $income], $key()), 'POST', '/products');                                                                    // service
        $this->assertConforms($this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'product'], $key()), 'POST', '/products');    // 422
        $this->assertConforms($this->api('GET', "/api/v1/products/{$id}", $t, $c), 'GET', '/products/{id}');                              // unmasked
        $this->assertConforms($this->api('GET', "/api/v1/products/{$id}", $store, $c), 'GET', '/products/{id}');                          // masked (BR-052)
        $this->assertConforms($this->api('GET', '/api/v1/products/999999', $t, $c), 'GET', '/products/{id}');                            // 404
        $this->assertConforms($this->api('PATCH', "/api/v1/products/{$id}", $t, $c, ['selling_price' => '930.00'], ['If-Match' => 'W/"1"']), 'PATCH', '/products/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/products/{$id}", $t, $c, ['on_hand' => '5'], ['If-Match' => 'W/"2"']), 'PATCH', '/products/{id}'); // 422
        $this->assertConforms($this->api('PATCH', "/api/v1/products/{$id}", $t, $c, ['name' => 'X'], ['If-Match' => 'W/"1"']), 'PATCH', '/products/{id}'); // 409

        $this->assertConforms($this->api('GET', "/api/v1/products?q=oil&sort=-on_hand&warehouse_id={$w}&per_page=5", $t, $c), 'GET', '/products');
        $this->assertConforms($this->api('GET', '/api/v1/products?active=all', $store, $c), 'GET', '/products');                        // masked
        $this->assertConforms($this->api('GET', '/api/v1/products?stock_status=low', $t, $c), 'GET', '/products');                      // 422
        $this->assertConforms($this->api('DELETE', "/api/v1/products/{$id}", $t, $c), 'DELETE', '/products/{id}');
        $this->assertConforms($this->api('DELETE', "/api/v1/products/{$id}", $t, $c), 'DELETE', '/products/{id}');                       // 404
    }

    public function test_two_factor_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $start = $this->api('POST', '/api/v1/me/two-factor', $t);
        $this->assertConforms($start, 'POST', '/me/two-factor');
        parse_str((string) parse_url($start->json('data.otpauth_uri'), PHP_URL_QUERY), $q);
        $code = fn () => Totp::code($q['secret'], Totp::step(now()->getTimestamp()));

        $this->assertConforms($this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => 'abc']), 'POST', '/me/two-factor/confirm');          // 422
        $this->assertConforms($this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $code()]), 'POST', '/me/two-factor/confirm');        // 201
        $this->assertConforms($this->api('POST', '/api/v1/me/two-factor', $t), 'POST', '/me/two-factor');                                                   // 409

        $step1 = $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => self::PASSWORD]);
        $this->assertConforms($step1, 'POST', '/auth/login');                                                                                              // challenge
        $challenge = $step1->json('data.challenge_token');
        $this->assertConforms($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge]), 'POST', '/auth/two-factor');                 // 422
        $this->assertConforms($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => '000000']), 'POST', '/auth/two-factor'); // 401
        $this->travel(30)->seconds();
        $this->assertConforms($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $code()]), 'POST', '/auth/two-factor'); // 200

        $this->assertConforms($this->api('DELETE', '/api/v1/me/two-factor', $t, null, ['password' => 'wrong password']), 'DELETE', '/me/two-factor');      // 401
        $this->assertConforms($this->api('DELETE', '/api/v1/me/two-factor', $t, null, ['password' => self::PASSWORD]), 'DELETE', '/me/two-factor');        // 204
    }

    public function test_purchase_bill_calculate_matches_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $supplier = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333'], $key())->json('data.id');
        $wh = $this->api('POST', '/api/v1/warehouses', $t, $c, ['code' => 'WH-1', 'name' => 'Main', 'branch_id' => \DB::table('branches')->where('company_id', $c)->value('id')], $key())->json('data.id');
        $product = $this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'product', 'name' => 'Rice', 'unit_id' => \DB::table('units')->where('company_id', $c)->value('id'),
            'selling_price' => '1.00', 'income_ledger_id' => \DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id')], $key())->json('data.id');
        $body = ['party_id' => $supplier, 'supplier_bill_no' => 'CST-1', 'bill_date' => now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $wh,
            'lines' => [['product_id' => $product, 'quantity' => '10', 'unit_cost' => '2340.00', 'discount_pct' => '2']]];
        $this->assertConforms($this->api('POST', '/api/v1/purchase-bills/calculate', $t, $c, $body), 'POST', '/purchase-bills/calculate');
        $this->assertConforms($this->api('POST', '/api/v1/purchase-bills/calculate', $t, $c, ['lines' => []]), 'POST', '/purchase-bills/calculate');    // 422
        $this->assertConforms($this->api('POST', '/api/v1/purchase-bills/calculate', $this->login('store@meghna.example'), $c, $body), 'POST', '/purchase-bills/calculate'); // 403

        $bill = $this->api('POST', '/api/v1/purchase-bills', $t, $c, $body, $key());
        $this->assertConforms($bill, 'POST', '/purchase-bills');
        $this->assertConforms($this->api('POST', '/api/v1/purchase-bills', $t, $c, $body, $key()), 'POST', '/purchase-bills');                 // 409 duplicate_supplier_bill
        $this->assertConforms($this->api('POST', '/api/v1/purchase-bills', $t, $c, ['lines' => []], $key()), 'POST', '/purchase-bills');        // 422
        $id = $bill->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/purchase-bills/{$id}", $t, $c), 'GET', '/purchase-bills/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/purchase-bills/999999', $t, $c), 'GET', '/purchase-bills/{id}');                     // 404
        $this->assertConforms($this->api('PUT', "/api/v1/purchase-bills/{$id}", $t, $c, $body), 'PUT', '/purchase-bills/{id}');                // 428
        $this->assertConforms($this->api('PUT', "/api/v1/purchase-bills/{$id}", $t, $c, $body, ['If-Match' => 'W/"1"']), 'PUT', '/purchase-bills/{id}');
        $this->assertConforms($this->api('PUT', "/api/v1/purchase-bills/{$id}", $t, $c, $body, ['If-Match' => 'W/"1"']), 'PUT', '/purchase-bills/{id}'); // 409
        $posted = $this->api('POST', '/api/v1/purchase-bills', $t, $c, ['supplier_bill_no' => 'CST-2'] + $body, $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/post", $t, $c, [], $key()), 'POST', '/purchase-bills/{id}/post');        // 200 with journal
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/post", $t, $c, [], $key()), 'POST', '/purchase-bills/{id}/post');        // 409 already_posted
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/post", $t, $c, ['when' => 'now'], $key()), 'POST', '/purchase-bills/{id}/post'); // 422
        $this->assertConforms($this->api('GET', "/api/v1/purchase-bills/{$posted}", $t, $c), 'GET', '/purchase-bills/{id}');                                 // posted, journal lines
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/cancel", $t, $c, [], $key()), 'POST', '/purchase-bills/{id}/cancel');      // 422 reason
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/cancel", $t, $c, ['reason' => 'duplicate'], $key()), 'POST', '/purchase-bills/{id}/cancel');
        $this->assertConforms($this->api('POST', "/api/v1/purchase-bills/{$posted}/cancel", $t, $c, ['reason' => 'duplicate'], $key()), 'POST', '/purchase-bills/{id}/cancel'); // 409
        $this->assertConforms($this->api('GET', '/api/v1/stock-movements?per_page=10', $t, $c), 'GET', '/stock-movements');
        $this->assertConforms($this->api('GET', '/api/v1/stock-movements', $this->login('store@meghna.example'), $c), 'GET', '/stock-movements');      // masked
        $this->assertConforms($this->api('GET', '/api/v1/stock-movements?movement_type=sale', $t, $c), 'GET', '/stock-movements');               // 422
        $this->assertConforms($this->api('GET', '/api/v1/purchase-bills?q=CST&status=draft&sort=-total&per_page=5', $t, $c), 'GET', '/purchase-bills');
        $this->assertConforms($this->api('GET', '/api/v1/purchase-bills?status=open', $t, $c), 'GET', '/purchase-bills');                     // 422
        $this->assertConforms($this->api('DELETE', "/api/v1/purchase-bills/{$id}", $t, $c), 'DELETE', '/purchase-bills/{id}');
        $this->assertConforms($this->api('DELETE', "/api/v1/purchase-bills/{$id}", $t, $c), 'DELETE', '/purchase-bills/{id}');                 // 404
    }

    public function test_the_validator_rejects_bodies_that_break_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $good = json_decode($this->api('POST', '/api/v1/parties', $t, $this->company->id, ['party_type' => 'customer', 'name' => 'Rahman Stores',
            'mobile' => '01711458230'])->assertCreated()->getContent());
        $ptr = self::pointer(['paths', '/api/v1/parties', 'post', 'responses', '201', 'content', 'application/json', 'schema']);
        $this->assertTrue($this->validate($good, $ptr)->isValid());

        $moneyAsNumber = json_decode(json_encode($good));
        $moneyAsNumber->data->balance = 0;
        $this->assertFalse($this->validate($moneyAsNumber, $ptr)->isValid(), 'money must be a decimal string');

        $missingData = (object) ['party' => $good->data];
        $this->assertFalse($this->validate($missingData, $ptr)->isValid(), 'the data envelope is required');
    }

    private function validate(mixed $data, string $pointer): ValidationResult
    {
        $validator = new Validator;
        $validator->parser()->setOption('defaultDraft', '2020-12');
        $validator->setMaxErrors(5);
        $validator->resolver()->registerRaw(self::doc(), self::DOC_URI);

        return $validator->validate($data, self::DOC_URI.'#'.$pointer);
    }

    private function assertConforms(TestResponse $r, string $method, string $path): void
    {
        $doc = self::doc();
        $pathKey = '/api/v1'.$path;
        $op = $doc->paths->{$pathKey}->{strtolower($method)} ?? null;
        $this->assertNotNull($op, "{$method} {$pathKey} is not in the contract");
        $status = (string) $r->status();
        $this->assertTrue(isset($op->responses->{$status}), "{$method} {$path} returned {$status}, which the contract does not declare: ".$r->getContent());
        if ($status === '204') {
            $this->assertSame('', $r->getContent());

            return;
        }
        $ctype = str_starts_with((string) $r->headers->get('Content-Type'), 'application/problem+json') ? 'application/problem+json' : 'application/json';
        $this->assertTrue(isset($op->responses->{$status}->content->{$ctype}), "{$method} {$path} {$status}: contract has no {$ctype} body");

        $pointer = self::pointer(['paths', $pathKey, strtolower($method), 'responses', $status, 'content', $ctype, 'schema']);
        $data = json_decode($r->getContent());
        $result = $this->validate($data, $pointer);
        $this->assertTrue($result->isValid(), "{$method} {$path} {$status} does not match the contract: "
            .json_encode($result->hasError() ? (new ErrorFormatter)->format($result->error()) : [], JSON_PRETTY_PRINT).' body: '.$r->getContent());

        if ($ctype === 'application/json' && isset($data->data)) {
            $schema = $op->responses->{$status}->content->{$ctype}->schema->properties->data;
            $items = is_array($data->data) ? $data->data : [$data->data];
            $ref = $schema->{'$ref'} ?? ($schema->items->{'$ref'} ?? null);
            if ($ref) {
                $declared = array_keys((array) $doc->components->schemas->{basename($ref)}->properties);
                foreach ($items as $item) {
                    $extra = array_diff(array_keys((array) $item), $declared);
                    $this->assertSame([], array_values($extra), "{$method} {$path}: fields not in ".basename($ref).': '.implode(', ', $extra));
                }
            }
        }
    }

    /** JSON Pointer (RFC 6901) as a URI fragment: ~ and / escaped, then percent-encoded (e.g. {id}). */
    private static function pointer(array $segments): string
    {
        return '/'.implode('/', array_map(fn ($s) => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], (string) $s)), $segments));
    }

    private static function doc(): object
    {
        return self::$doc ??= json_decode(json_encode(Yaml::parseFile(base_path('docs/openapi.yaml'))));
    }
}
