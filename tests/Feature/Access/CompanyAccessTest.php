<?php

namespace Tests\Feature\Access;

use App\Domain\Access\RoleTemplates;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** X-Company-Id membership checks (07 §4, BR-037) and the K11 role matrix on the customer endpoints (07 §6, TC-AUTH-008). */
class CompanyAccessTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->makeUser('owner@meghna.example');
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $owner);
        foreach (['finance', 'accountant', 'sales', 'store', 'viewer'] as $slug) {
            $this->addMember($this->company, $this->makeUser("{$slug}@meghna.example"), $slug);
        }
    }

    public function test_company_scoped_calls_need_the_header_and_an_active_membership(): void
    {
        $token = $this->login('sales@meghna.example');
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $token), 400, 'company_required');
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $token, 999999), 403, 'not_a_member');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $token, $other->id), 403, 'not_a_member');

        $this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id)->assertOk();
    }

    public function test_a_deactivated_membership_loses_access_immediately(): void
    {
        $token = $this->login('viewer@meghna.example');
        $this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id)->assertOk();
        \DB::table('company_users')->where('user_id', User::where('email', 'viewer@meghna.example')->value('id'))->update(['status' => 'deactivated']);
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id), 403, 'not_a_member');
    }

    public function test_me_returns_permissions_only_for_the_selected_company(): void
    {
        $token = $this->login('sales@meghna.example');
        $plain = $this->api('GET', '/api/v1/me', $token)->assertOk();
        $this->assertNull($plain->json('data.permissions'));
        $plain->assertJsonPath('data.active_company_id', null)->assertJsonPath('data.companies.0.role', 'Sales staff');

        $scoped = $this->api('GET', '/api/v1/me', $token, $this->company->id)->assertOk();
        $this->assertSame(RoleTemplates::ROLES['sales'][1], $scoped->json('data.permissions'));
        $scoped->assertJsonPath('data.active_company_id', $this->company->id)->assertJsonPath('data.approval_limits.payment', '0.00');
    }

    public function test_editing_a_role_takes_effect_on_the_next_request(): void
    {
        $token = $this->login('store@meghna.example');
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id), 403, 'permission_denied');

        app(CurrentCompany::class)->set($this->company, null);
        $role = Role::where('slug', 'store')->first();
        $role->permissions()->attach(Permission::where('key', 'parties.view')->value('id'));
        $role->update(['description' => 'Can now look up customers']); // a role edit bumps lock_version, invalidating the permission cache
        app(CurrentCompany::class)->clear();

        $this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id)->assertOk();
    }

    /** Every role x every customer endpoint: allowed exactly when the K11 matrix grants the permission. */
    #[DataProvider('matrix')]
    public function test_role_matrix_on_customer_endpoints(string $role, string $permission, string $method, string $uri): void
    {
        $owner = $this->login('owner@meghna.example');
        $id = $this->api('POST', '/api/v1/parties', $owner, $this->company->id, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711-458230'])
            ->assertCreated()->json('data.id');
        $email = $role === 'owner' ? 'owner@meghna.example' : "{$role}@meghna.example";
        $token = $this->login($email, '10.0.1.'.random_int(2, 250));
        $uri = str_replace('{id}', (string) $id, $uri);
        $body = match ($method) {
            'POST' => str_ends_with($uri, '/parties') ? ['party_type' => 'customer', 'name' => 'Karim Traders', 'mobile' => '01812-000111'] : [],
            'PATCH' => ['notes' => 'Prefers morning delivery'],
            default => [],
        };
        $headers = $method === 'PATCH' ? ['If-Match' => 'W/"1"'] : [];
        $r = $this->api($method, $uri, $token, $this->company->id, $body, $headers);

        $allowed = $role === 'owner' || in_array($permission, RoleTemplates::ROLES[$role][1], true);
        if ($allowed) {
            $this->assertContains($r->status(), [200, 201, 204], "{$role} should be allowed {$method} {$uri}: ".$r->getContent());
        } else {
            $this->assertProblem($r, 403, 'permission_denied');
        }
    }

    public static function matrix(): array
    {
        $calls = [
            ['parties.view', 'GET', '/api/v1/parties?type=customer'],
            ['parties.view', 'GET', '/api/v1/parties/{id}'],
            ['parties.view', 'GET', '/api/v1/party-groups?type=customer'],
            ['parties.create', 'POST', '/api/v1/parties'],
            ['parties.edit', 'PATCH', '/api/v1/parties/{id}'],
            ['parties.edit', 'POST', '/api/v1/parties/{id}/deactivate'],
            ['parties.delete', 'DELETE', '/api/v1/parties/{id}'],
        ];
        $cases = [];
        foreach (array_keys(RoleTemplates::ROLES) as $role) {
            foreach ($calls as [$perm, $method, $uri]) {
                $cases["{$role} {$method} {$uri}"] = [$role, $perm, $method, $uri];
            }
        }

        return $cases;
    }

    /** Every role x the purchase bill endpoints (P4.3-BE-01: a Store keeper reads bills but cannot post them). */
    #[DataProvider('billMatrix')]
    public function test_role_matrix_on_purchase_bill_endpoints(string $role, string $permission, string $method, string $uri): void
    {
        $owner = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        $supplier = $this->api('POST', '/api/v1/parties', $owner, $c, ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333'], $key())->json('data.id');
        $wh = $this->api('POST', '/api/v1/warehouses', $owner, $c, ['code' => 'WH-1', 'name' => 'Main', 'branch_id' => \DB::table('branches')->where('company_id', $c)->value('id')], $key())->json('data.id');
        $product = $this->api('POST', '/api/v1/products', $owner, $c, ['item_type' => 'product', 'name' => 'Rice', 'unit_id' => \DB::table('units')->where('company_id', $c)->value('id'),
            'selling_price' => '1.00', 'income_ledger_id' => \DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id')], $key())->json('data.id');
        $bill = fn (string $no) => ['party_id' => $supplier, 'supplier_bill_no' => $no, 'bill_date' => now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $wh,
            'lines' => [['product_id' => $product, 'quantity' => '1', 'unit_cost' => '10.00']]];
        $id = $this->api('POST', '/api/v1/purchase-bills', $owner, $c, $bill('SB-1'), $key())->assertCreated()->json('data.id');

        $token = $role === 'owner' ? $owner : $this->login("{$role}@meghna.example", '10.0.2.'.random_int(2, 250));
        $uri = str_replace('{id}', (string) $id, $uri);
        $r = $this->api($method, $uri, $token, $c, $method === 'POST' && str_ends_with($uri, '/purchase-bills') ? $bill('SB-2') : [], $method === 'POST' ? $key() : []);

        $allowed = $role === 'owner' || in_array($permission, RoleTemplates::ROLES[$role][1], true);
        if ($allowed) {
            $this->assertContains($r->status(), [200, 201], "{$role} should be allowed {$method} {$uri}: ".$r->getContent());
        } else {
            $this->assertProblem($r, 403, 'permission_denied');
        }
    }

    public static function billMatrix(): array
    {
        $calls = [
            ['purchases.view', 'GET', '/api/v1/purchase-bills'],
            ['purchases.view', 'GET', '/api/v1/purchase-bills/{id}'],
            ['purchases.create', 'POST', '/api/v1/purchase-bills'],
            ['purchases.create', 'POST', '/api/v1/purchase-bills/{id}/post'],
        ];
        $cases = [];
        foreach (array_keys(RoleTemplates::ROLES) as $role) {
            foreach ($calls as [$perm, $method, $uri]) {
                $cases["{$role} {$method} {$uri}"] = [$role, $perm, $method, $uri];
            }
        }

        return $cases;
    }
}
