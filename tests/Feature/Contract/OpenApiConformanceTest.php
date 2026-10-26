<?php

namespace Tests\Feature\Contract;

use App\Domain\Access\Totp;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\Company;
use App\Support\CurrentCompany;
use Database\Seeders\DemoTaxRuleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    public function test_sales_invoice_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $customer = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Karim Distributors', 'mobile' => '01711458230',
            'credit_limit' => '100.00'], $key())->json('data.id');
        $wh = $this->api('POST', '/api/v1/warehouses', $t, $c, ['code' => 'WH-1', 'name' => 'Main', 'branch_id' => \DB::table('branches')->where('company_id', $c)->value('id')], $key())->json('data.id');
        $unit = \DB::table('units')->where('company_id', $c)->value('id');
        $income = fn (string $code) => \DB::table('ledgers')->where('company_id', $c)->where('code', $code)->value('id');
        $oil = $this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'product', 'name' => 'Oil', 'unit_id' => $unit, 'selling_price' => '940.00',
            'income_ledger_id' => $income('4101')], $key())->json('data.id');
        $trip = $this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'service', 'name' => 'Delivery', 'unit_id' => $unit, 'selling_price' => '150.00',
            'income_ledger_id' => $income('4201')], $key())->json('data.id');
        $body = ['party_id' => $customer, 'invoice_date' => now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $wh,
            'lines' => [['product_id' => $oil, 'quantity' => '24', 'discount_pct' => '2'], ['product_id' => $trip, 'quantity' => '1']]];
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices/calculate', $t, $c, $body), 'POST', '/sales-invoices/calculate');   // LOW_STOCK, credit limit
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices/calculate', $t, $c, ['lines' => []]), 'POST', '/sales-invoices/calculate'); // 422
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices/calculate', $this->login('store@meghna.example'), $c, $body), 'POST', '/sales-invoices/calculate'); // 403

        $inv = $this->api('POST', '/api/v1/sales-invoices', $t, $c, $body, $key());
        $this->assertConforms($inv, 'POST', '/sales-invoices');
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices', $t, $c, ['lines' => []], $key()), 'POST', '/sales-invoices');         // 422
        $id = $inv->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/sales-invoices/{$id}", $t, $c), 'GET', '/sales-invoices/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/sales-invoices/999999', $t, $c), 'GET', '/sales-invoices/{id}');                      // 404
        $this->assertConforms($this->api('PUT', "/api/v1/sales-invoices/{$id}", $t, $c, $body), 'PUT', '/sales-invoices/{id}');                 // 428
        $this->assertConforms($this->api('PUT', "/api/v1/sales-invoices/{$id}", $t, $c, $body, ['If-Match' => 'W/"1"']), 'PUT', '/sales-invoices/{id}');
        $this->assertConforms($this->api('PUT', "/api/v1/sales-invoices/{$id}", $t, $c, $body, ['If-Match' => 'W/"1"']), 'PUT', '/sales-invoices/{id}');  // 409
        $this->assertConforms($this->api('GET', '/api/v1/sales-invoices?q=Karim&status=draft&sort=-total&per_page=5', $t, $c), 'GET', '/sales-invoices');
        $this->assertConforms($this->api('GET', '/api/v1/sales-invoices?status=open', $t, $c), 'GET', '/sales-invoices');                      // 422
        $this->assertConforms($this->api('DELETE', "/api/v1/sales-invoices/{$id}", $t, $c), 'DELETE', '/sales-invoices/{id}');
        $this->assertConforms($this->api('DELETE', "/api/v1/sales-invoices/{$id}", $t, $c), 'DELETE', '/sales-invoices/{id}');                  // 404

        $short = $this->api('POST', '/api/v1/sales-invoices', $t, $c, $body, $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$short}/post", $t, $c, [], $key()), 'POST', '/sales-invoices/{id}/post');      // 422 insufficient_stock
        $service = $this->api('POST', '/api/v1/sales-invoices', $t, $c, ['lines' => [['product_id' => $trip, 'quantity' => '2']]] + $body, $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/post", $t, $c, ['acknowledge_warnings' => ['CREDIT_LIMIT_EXCEEDED']], $key()), 'POST', '/sales-invoices/{id}/post');
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/post", $t, $c, [], $key()), 'POST', '/sales-invoices/{id}/post');    // 409 already_posted
        $this->assertConforms($this->api('GET', "/api/v1/sales-invoices/{$service}", $t, $c), 'GET', '/sales-invoices/{id}');                             // posted, journal lines
        Mail::fake();
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/send", $t, $c, ['to' => ['a@karim.example']], $key()), 'POST', '/sales-invoices/{id}/send'); // 202
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/send", $t, $c, ['to' => ['nope']], $key()), 'POST', '/sales-invoices/{id}/send');         // 422
        $this->assertConforms($this->api('GET', "/api/v1/sales-invoices/{$service}/pdf?variant=receipt", $t, $c), 'GET', '/sales-invoices/{id}/pdf');                       // 422

        $this->assertConforms($this->api('PATCH', "/api/v1/sales-invoices/{$service}/delivery", $t, $c, ['challan_no' => 'DC-1']), 'PATCH', '/sales-invoices/{id}/delivery');  // 428
        $this->assertConforms($this->api('PATCH', "/api/v1/sales-invoices/{$service}/delivery", $t, $c, ['challan_no' => 'DC-1', 'delivered_at' => '2026-10-24T15:30:00+06:00'],
            ['If-Match' => 'W/"2"']), 'PATCH', '/sales-invoices/{id}/delivery');
        $this->assertConforms($this->api('PATCH', "/api/v1/sales-invoices/{$service}/delivery", $t, $c, ['colour' => 'red'], ['If-Match' => 'W/"3"']), 'PATCH', '/sales-invoices/{id}/delivery'); // 422
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices/bulk-post', $t, $c, ['ids' => [$short, $service]], $key()), 'POST', '/sales-invoices/bulk-post');
        $this->assertConforms($this->api('POST', '/api/v1/sales-invoices/bulk-post', $t, $c, ['ids' => []], $key()), 'POST', '/sales-invoices/bulk-post');            // 422
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/cancel", $t, $c, [], $key()), 'POST', '/sales-invoices/{id}/cancel');             // 422 reason
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/cancel", $t, $c, ['reason' => 'duplicate'], $key()), 'POST', '/sales-invoices/{id}/cancel');
        $this->assertConforms($this->api('POST', "/api/v1/sales-invoices/{$service}/cancel", $t, $c, ['reason' => 'duplicate'], $key()), 'POST', '/sales-invoices/{id}/cancel'); // 409
    }

    public function test_money_account_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.', 'account_number' => '0012-4521', 'opening_balance' => '500000.00'];
        $a = $this->api('POST', '/api/v1/money-accounts', $t, $c, $body, $key());
        $this->assertConforms($a, 'POST', '/money-accounts');
        $this->assertConforms($this->api('POST', '/api/v1/money-accounts', $t, $c, ['account_type' => 'bank'], $key()), 'POST', '/money-accounts');   // 422
        $id = $a->json('data.id');
        $this->assertConforms($this->api('GET', '/api/v1/money-accounts?type=bank', $t, $c), 'GET', '/money-accounts');
        $this->assertConforms($this->api('GET', "/api/v1/money-accounts/{$id}", $t, $c), 'GET', '/money-accounts/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/money-accounts/999999', $t, $c), 'GET', '/money-accounts/{id}');                             // 404
        $this->assertConforms($this->api('PATCH', "/api/v1/money-accounts/{$id}", $t, $c, ['name' => 'Sonali current']), 'PATCH', '/money-accounts/{id}');       // 428
        $this->assertConforms($this->api('PATCH', "/api/v1/money-accounts/{$id}", $t, $c, ['name' => 'Sonali current'], ['If-Match' => 'W/"1"']), 'PATCH', '/money-accounts/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/money-accounts/{$id}", $t, $c, ['name' => 'Again'], ['If-Match' => 'W/"1"']), 'PATCH', '/money-accounts/{id}'); // 409
        $this->assertConforms($this->api('GET', "/api/v1/money-accounts/{$id}", $this->login('store@meghna.example'), $c), 'GET', '/money-accounts/{id}'); // 403
    }

    public function test_account_group_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $parent = (int) DB::table('account_groups')->where('company_id', $c)->where('code', '61')->value('id');
        $this->assertConforms($this->api('GET', '/api/v1/account-groups', $t, $c), 'GET', '/account-groups');
        $this->assertConforms($this->api('GET', '/api/v1/account-groups?zero=maybe', $t, $c), 'GET', '/account-groups');                                   // 422
        $a = $this->api('POST', '/api/v1/account-groups', $t, $c, ['code' => '6103', 'name' => 'Office running costs', 'parent_id' => $parent]);
        $this->assertConforms($a, 'POST', '/account-groups');
        $this->assertConforms($this->api('POST', '/api/v1/account-groups', $t, $c, ['code' => '6103']), 'POST', '/account-groups');                          // 422
        $id = $a->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/account-groups/{$parent}", $t, $c), 'GET', '/account-groups/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/account-groups/999999', $t, $c), 'GET', '/account-groups/{id}');                                 // 404
        $this->assertConforms($this->api('PATCH', "/api/v1/account-groups/{$id}", $t, $c, ['name' => 'Office costs']), 'PATCH', '/account-groups/{id}');      // 428
        $this->assertConforms($this->api('PATCH', "/api/v1/account-groups/{$id}", $t, $c, ['name' => 'Office costs'], ['If-Match' => 'W/"1"']), 'PATCH', '/account-groups/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/account-groups/{$id}", $t, $c, ['name' => 'Again'], ['If-Match' => 'W/"1"']), 'PATCH', '/account-groups/{id}'); // 409
        $income = (int) DB::table('account_groups')->where('company_id', $c)->where('code', '41')->value('id');
        $this->assertConforms($this->api('PATCH', "/api/v1/account-groups/{$id}", $t, $c, ['parent_id' => $income], ['If-Match' => 'W/"2"']), 'PATCH', '/account-groups/{id}'); // 422 nature
        $this->assertConforms($this->api('GET', '/api/v1/account-groups', $this->login('store@meghna.example'), $c), 'GET', '/account-groups');            // 403
    }

    public function test_ledger_management_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $group = (int) DB::table('account_groups')->where('company_id', $c)->where('code', '61')->value('id');
        $a = $this->api('POST', '/api/v1/ledgers', $t, $c, ['name' => 'Generator fuel', 'account_group_id' => $group, 'opening_balance' => '500.00', 'opening_side' => 'dr'], $key());
        $this->assertConforms($a, 'POST', '/ledgers');
        $this->assertConforms($this->api('POST', '/api/v1/ledgers', $t, $c, ['name' => 'X'], $key()), 'POST', '/ledgers');                                 // 422
        $id = $a->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/ledgers/{$id}", $t, $c), 'GET', '/ledgers/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/ledgers/999999', $t, $c), 'GET', '/ledgers/{id}');                                               // 404
        $this->assertConforms($this->api('PATCH', "/api/v1/ledgers/{$id}", $t, $c, ['name' => 'Fuel']), 'PATCH', '/ledgers/{id}');                        // 428
        $this->assertConforms($this->api('PATCH', "/api/v1/ledgers/{$id}", $t, $c, ['name' => 'Fuel'], ['If-Match' => 'W/"1"']), 'PATCH', '/ledgers/{id}');
        $this->assertConforms($this->api('PATCH', "/api/v1/ledgers/{$id}", $t, $c, ['name' => 'Again'], ['If-Match' => 'W/"1"']), 'PATCH', '/ledgers/{id}'); // 409
        $income = (int) DB::table('account_groups')->where('company_id', $c)->where('code', '41')->value('id');
        $this->assertConforms($this->api('PATCH', "/api/v1/ledgers/{$id}", $t, $c, ['account_group_id' => $income], ['If-Match' => 'W/"2"']), 'PATCH', '/ledgers/{id}'); // 422 nature
        $this->assertConforms($this->api('POST', "/api/v1/ledgers/{$id}/deactivate", $t, $c), 'POST', '/ledgers/{id}/deactivate');                       // 409 balance
        $spare = $this->api('POST', '/api/v1/ledgers', $t, $c, ['name' => 'Spare', 'account_group_id' => $group], $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/ledgers/{$spare}/deactivate", $t, $c), 'POST', '/ledgers/{id}/deactivate');
        $this->assertConforms($this->api('GET', "/api/v1/ledgers/{$id}", $this->login('store@meghna.example'), $c), 'GET', '/ledgers/{id}');            // 403
        $this->assertConforms($this->api('GET', "/api/v1/ledgers/{$id}/entries", $t, $c), 'GET', '/ledgers/{id}/entries');
        $this->assertConforms($this->api('GET', "/api/v1/ledgers/{$id}/entries?date_from=bad", $t, $c), 'GET', '/ledgers/{id}/entries');                 // 422
        $this->assertConforms($this->api('GET', '/api/v1/accounting/overview', $t, $c), 'GET', '/accounting/overview');
    }

    public function test_voucher_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $ledger = fn (string $code) => (int) DB::table('ledgers')->where('company_id', $c)->where('code', $code)->value('id');
        $jv = fn (string $action, string $amount = '5000.00') => ['entry_type' => 'journal', 'action' => $action, 'date' => now('Asia/Dhaka')->toDateString(), 'narration' => 'Depreciation',
            'lines' => [['ledger_id' => $ledger('6501'), 'debit' => $amount], ['ledger_id' => $ledger('1509'), 'credit' => $amount]]];
        $this->assertConforms($this->api('POST', '/api/v1/vouchers/calculate', $t, $c, $jv('post')), 'POST', '/vouchers/calculate');
        $this->assertConforms($this->api('POST', '/api/v1/vouchers/calculate', $t, $c, ['entry_type' => 'journal']), 'POST', '/vouchers/calculate');          // 422
        $draft = $this->api('POST', '/api/v1/vouchers', $t, $c, $jv('draft'), $key());
        $this->assertConforms($draft, 'POST', '/vouchers');
        $id = $draft->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/vouchers/{$id}", $t, $c), 'GET', '/vouchers/{id}');
        $this->assertConforms($this->api('PUT', "/api/v1/vouchers/{$id}", $t, $c, $jv('draft', '6000.00'), ['If-Match' => 'W/"1"']), 'PUT', '/vouchers/{id}');
        $this->assertConforms($this->api('POST', "/api/v1/vouchers/{$id}/post", $t, $c, [], $key()), 'POST', '/vouchers/{id}/post');
        $this->assertConforms($this->api('DELETE', "/api/v1/vouchers/{$id}", $t, $c), 'DELETE', '/vouchers/{id}');                                         // 409
        $other = $this->api('POST', '/api/v1/vouchers', $t, $c, $jv('draft'), $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/vouchers/{$other}/submit", $t, $c), 'POST', '/vouchers/{id}/submit');
        $bad = $jv('post');
        $bad['lines'][1]['credit'] = '1.00';
        $this->assertConforms($this->api('POST', '/api/v1/vouchers', $t, $c, $bad, $key()), 'POST', '/vouchers');                                             // 422 unbalanced
        $this->assertConforms($this->api('GET', '/api/v1/vouchers?entry_type=journal', $t, $c), 'GET', '/vouchers');
        $this->assertConforms($this->api('GET', '/api/v1/voucher-templates', $t, $c), 'GET', '/voucher-templates');
        $this->assertConforms($this->api('POST', '/api/v1/vouchers', $this->login('store@meghna.example'), $c, $jv('post'), $key()), 'POST', '/vouchers');     // 403
    }

    public function test_approval_and_notification_endpoints_match_the_contract(): void
    {
        $c = $this->company->id;
        $this->addMember($this->company, $this->makeUser('accountant@meghna.example'), 'accountant');
        $owner = $this->login('owner@meghna.example');
        $acc = $this->login('accountant@meghna.example');
        $ledger = fn (string $code) => (int) DB::table('ledgers')->where('company_id', $c)->where('code', $code)->value('id');
        $jv = fn () => ['entry_type' => 'journal', 'action' => 'post', 'date' => now('Asia/Dhaka')->toDateString(),
            'lines' => [['ledger_id' => $ledger('6501'), 'debit' => '150000.00'], ['ledger_id' => $ledger('1509'), 'credit' => '150000.00']]];
        $v = $this->api('POST', '/api/v1/vouchers', $acc, $c, $jv(), ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertConforms($v, 'POST', '/vouchers');                                                                                                 // 202
        $id = $v->json('data.approval.id');
        $this->assertConforms($this->api('GET', '/api/v1/approvals', $owner, $c), 'GET', '/approvals');
        $this->assertConforms($this->api('GET', "/api/v1/approvals/{$id}", $owner, $c), 'GET', '/approvals/{id}');
        $this->assertConforms($this->api('POST', "/api/v1/approvals/{$id}/reject", $owner, $c, []), 'POST', '/approvals/{id}/reject');               // 422 comment
        $this->assertConforms($this->api('POST', "/api/v1/approvals/{$id}/approve", $acc, $c, [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/approvals/{id}/approve'); // 403 self
        $this->assertConforms($this->api('POST', "/api/v1/approvals/{$id}/approve", $owner, $c, ['comment' => 'OK'], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/approvals/{id}/approve');
        $second = $this->api('POST', '/api/v1/vouchers', $acc, $c, $jv(), ['Idempotency-Key' => (string) Str::uuid()])->json('data.approval.id');
        $this->assertConforms($this->api('POST', "/api/v1/approvals/{$second}/reject", $owner, $c, ['comment' => 'Split it']), 'POST', '/approvals/{id}/reject');
        $this->assertConforms($this->api('GET', '/api/v1/notifications?unread=true', $acc, $c), 'GET', '/notifications');
        $n = $this->api('GET', '/api/v1/notifications', $acc, $c)->json('data.0.id');
        $this->assertConforms($this->api('POST', "/api/v1/notifications/{$n}/read", $acc, $c), 'POST', '/notifications/{id}/read');
        $this->assertConforms($this->api('POST', '/api/v1/notifications/read-all', $acc, $c), 'POST', '/notifications/read-all');
    }

    public function test_reverse_period_and_mapping_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $ledger = fn (string $code) => (int) DB::table('ledgers')->where('company_id', $c)->where('code', $code)->value('id');
        $today = now('Asia/Dhaka')->toDateString();
        $id = $this->api('POST', '/api/v1/vouchers', $t, $c, ['entry_type' => 'journal', 'action' => 'post', 'date' => $today,
            'lines' => [['ledger_id' => $ledger('6501'), 'debit' => '100.00'], ['ledger_id' => $ledger('1509'), 'credit' => '100.00']]], $key())->json('data.id');
        $this->assertConforms($this->api('POST', "/api/v1/vouchers/{$id}/reverse", $t, $c, ['reversal_date' => $today, 'reason' => 'Twice'], $key()), 'POST', '/vouchers/{id}/reverse');
        $this->assertConforms($this->api('POST', "/api/v1/vouchers/{$id}/reverse", $t, $c, ['reversal_date' => $today, 'reason' => 'Again'], $key()), 'POST', '/vouchers/{id}/reverse'); // 409
        $this->assertConforms($this->api('POST', "/api/v1/vouchers/{$id}/reverse", $t, $c, [], $key()), 'POST', '/vouchers/{id}/reverse');                 // 422

        $periods = DB::table('accounting_periods')->where('company_id', $c)->orderBy('start_date')->pluck('id');
        $this->assertConforms($this->api('POST', "/api/v1/accounting-periods/{$periods[1]}/lock", $t, $c, []), 'POST', '/accounting-periods/{id}/lock');    // 422 order
        $this->assertConforms($this->api('POST', "/api/v1/accounting-periods/{$periods[0]}/lock", $t, $c, ['note' => 'Done']), 'POST', '/accounting-periods/{id}/lock');
        $this->assertConforms($this->api('POST', "/api/v1/accounting-periods/{$periods[0]}/unlock", $t, $c, []), 'POST', '/accounting-periods/{id}/unlock'); // 422
        $this->assertConforms($this->api('POST', "/api/v1/accounting-periods/{$periods[0]}/unlock", $t, $c, ['reason' => 'Late bill']), 'POST', '/accounting-periods/{id}/unlock');
        $fy = DB::table('fiscal_years')->where('company_id', $c)->value('id');
        $this->assertConforms($this->api('POST', "/api/v1/fiscal-years/{$fy}/close", $t, $c, [], $key()), 'POST', '/fiscal-years/{id}/close');               // 422
        $this->assertConforms($this->api('GET', '/api/v1/fiscal-years', $t, $c), 'GET', '/fiscal-years');

        $m = $this->api('GET', '/api/v1/account-mappings', $t, $c);
        $this->assertConforms($m, 'GET', '/account-mappings');
        $this->assertConforms($this->api('PUT', '/api/v1/account-mappings', $t, $c, ['mappings' => [['mapping_key' => 'cogs', 'ledger_id' => $ledger('5001'), 'required_nature' => 'expense']]]),
            'PUT', '/account-mappings');
        $this->assertConforms($this->api('PUT', '/api/v1/account-mappings', $t, $c, ['mappings' => [['mapping_key' => 'cogs', 'ledger_id' => $ledger('4101')]]]), 'PUT', '/account-mappings'); // 422
    }

    public function test_supplier_payment_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $supplier = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333'], $key())->json('data.id');
        $bank = $this->api('POST', '/api/v1/money-accounts', $t, $c, ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012-4521', 'opening_balance' => '50000.00'], $key())->json('data.id');
        $body = ['party_id' => $supplier, 'date' => now('Asia/Dhaka')->toDateString(), 'amount' => '1000.00', 'money_account_id' => $bank, 'method' => 'cash'];
        $p = $this->api('POST', '/api/v1/supplier-payments', $t, $c, $body, $key());
        $this->assertConforms($p, 'POST', '/supplier-payments');
        $this->assertConforms($this->api('POST', '/api/v1/supplier-payments', $t, $c, ['amount' => '0'], $key()), 'POST', '/supplier-payments');                      // 422
        $this->assertConforms($this->api('POST', '/api/v1/supplier-payments', $t, $c, ['amount' => '90000.00'] + $body, $key()), 'POST', '/supplier-payments');        // 422 funds
        $this->assertConforms($this->api('GET', '/api/v1/supplier-payments?status=posted', $t, $c), 'GET', '/supplier-payments');
        $this->assertConforms($this->api('GET', "/api/v1/supplier-payments/{$p->json('data.id')}", $t, $c), 'GET', '/supplier-payments/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/supplier-payments/999999', $t, $c), 'GET', '/supplier-payments/{id}');                                         // 404
    }

    public function test_allocation_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $customer = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Karim Distributors', 'mobile' => '01711458230'], $key())->json('data');
        $wh = $this->api('POST', '/api/v1/warehouses', $t, $c, ['code' => 'WH-1', 'name' => 'Main', 'branch_id' => \DB::table('branches')->where('company_id', $c)->value('id')], $key())->json('data.id');
        $trip = $this->api('POST', '/api/v1/products', $t, $c, ['item_type' => 'service', 'name' => 'Delivery', 'unit_id' => \DB::table('units')->where('company_id', $c)->value('id'),
            'selling_price' => '150.00', 'income_ledger_id' => \DB::table('ledgers')->where('company_id', $c)->where('code', '4201')->value('id')], $key())->json('data.id');
        $inv = $this->api('POST', '/api/v1/sales-invoices', $t, $c, ['party_id' => $customer['id'], 'invoice_date' => now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $wh,
            'lines' => [['product_id' => $trip, 'quantity' => '10']]], $key())->json('data.id');
        $this->api('POST', "/api/v1/sales-invoices/{$inv}/post", $t, $c, [], $key())->assertOk();
        app(CurrentCompany::class)->set($this->company, null);
        $cash = \DB::table('ledgers')->where('company_id', $c)->where('code', '1101-01')->value('id');
        $partyLedger = \DB::table('parties')->where('id', $customer['id'])->value('ledger_id');
        $entry = app(PostingService::class)->persist((new JournalBuilder)->debit($cash, Money::of('1000'))->credit($partyLedger, Money::of('1000')),
            null, 'journal', now('Asia/Dhaka'), null, 'Advance');
        $line = $entry->lines->firstWhere('ledger_id', $partyLedger)->id;

        $this->assertConforms($this->api('GET', "/api/v1/parties/{$customer['id']}/open-documents", $t, $c), 'GET', '/parties/{id}/open-documents');
        $a = $this->api('POST', '/api/v1/allocations', $t, $c, ['journal_line_id' => $line, 'document_type' => 'sales_invoice', 'document_id' => $inv, 'amount' => '500.00'], $key());
        $this->assertConforms($a, 'POST', '/allocations');
        $this->assertConforms($this->api('POST', '/api/v1/allocations', $t, $c, ['journal_line_id' => $line, 'document_type' => 'sales_invoice', 'document_id' => $inv,
            'amount' => '5000.00'], $key()), 'POST', '/allocations');                                                                                         // 422 exceeds
        $this->assertConforms($this->api('GET', "/api/v1/sales-invoices/{$inv}", $t, $c), 'GET', '/sales-invoices/{id}');                               // with allocations
        $this->assertConforms($this->api('DELETE', '/api/v1/allocations/'.$a->json('data.id'), $t, $c), 'DELETE', '/allocations/{id}');
        $this->assertConforms($this->api('DELETE', '/api/v1/allocations/'.$a->json('data.id'), $t, $c), 'DELETE', '/allocations/{id}');               // 404
    }

    public function test_customer_receipt_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $customer = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Karim Distributors', 'mobile' => '01711458230'], $key())->json('data.id');
        $account = $this->api('POST', '/api/v1/money-accounts', $t, $c, ['account_type' => 'cash'], $key())->json('data.id');
        $body = ['party_id' => $customer, 'date' => now('Asia/Dhaka')->toDateString(), 'amount' => '5000.00', 'money_account_id' => $account, 'method' => 'cash', 'auto_allocate' => true];
        $r = $this->api('POST', '/api/v1/customer-receipts', $t, $c, $body, $key());
        $this->assertConforms($r, 'POST', '/customer-receipts');
        $this->assertConforms($this->api('POST', '/api/v1/customer-receipts', $t, $c, ['method' => 'cheque'] + $body, $key()), 'POST', '/customer-receipts'); // 422
        $this->assertConforms($this->api('GET', '/api/v1/customer-receipts?unapplied=true&per_page=5', $t, $c), 'GET', '/customer-receipts');
        $this->assertConforms($this->api('GET', '/api/v1/customer-receipts/'.$r->json('data.id'), $t, $c), 'GET', '/customer-receipts/{id}');
        $this->assertConforms($this->api('GET', '/api/v1/customer-receipts/999999', $t, $c), 'GET', '/customer-receipts/{id}');                         // 404
        $this->assertConforms($this->api('GET', '/api/v1/customer-receipts', $this->login('store@meghna.example'), $c), 'GET', '/customer-receipts');     // 403
    }

    public function test_party_account_endpoints_match_the_contract(): void
    {
        $t = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $id = $this->api('POST', '/api/v1/parties', $t, $c, ['party_type' => 'customer', 'name' => 'Rahman Super Shop', 'mobile' => '01811000222',
            'opening_balance' => '185000.00'], $key())->json('data.id');
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}", $t, $c), 'GET', '/parties/{id}');
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}/ledger", $t, $c), 'GET', '/parties/{id}/ledger');
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}/ledger?colour=red", $t, $c), 'GET', '/parties/{id}/ledger');      // 422
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}/transactions", $t, $c), 'GET', '/parties/{id}/transactions');
        $this->assertConforms($this->api('GET', '/api/v1/parties/outstanding?type=customer', $t, $c), 'GET', '/parties/outstanding');
        $this->assertConforms($this->api('GET', "/api/v1/parties/{$id}/statement?aging=true", $t, $c), 'GET', '/parties/{id}/statement');
        $this->assertConforms($this->api('GET', '/api/v1/parties/999999/statement', $t, $c), 'GET', '/parties/{id}/statement');             // 404
        Mail::fake();
        $this->assertConforms($this->api('POST', '/api/v1/parties/statements/send', $t, $c, ['party_ids' => [$id], 'date_from' => now('Asia/Dhaka')->toDateString(),
            'date_to' => now('Asia/Dhaka')->toDateString()], $key()), 'POST', '/parties/statements/send');                                   // 202 ExportJob
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
        if ($status === '204' || ! isset($op->responses->{$status}->content)) { // 204, or e.g. 202 accepted without a body
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
