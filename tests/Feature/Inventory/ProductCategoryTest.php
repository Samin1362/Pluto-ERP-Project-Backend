<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** /product-categories (D5, backlog P3.1-BE-02; BR-026): two-level tree, cycle and depth checks, delete only when unused. */
class ProductCategoryTest extends TestCase
{
    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data);
    }

    private function as(string $role): string
    {
        $this->addMember($this->company, $this->makeUser("{$role}@meghna.example"), $role);

        return $this->login("{$role}@meghna.example");
    }

    private function category(string $name, ?int $parent = null, array $extra = []): int
    {
        return $this->req('POST', '/api/v1/product-categories', ['name' => $name, 'parent_id' => $parent] + $extra)->assertCreated()->json('data.id');
    }

    /** A product row in the category (products are created through the API from P3.1-BE-05 on). */
    private function productIn(int $categoryId, string $code): int
    {
        $c = $this->company->id;

        return DB::table('products')->insertGetId([
            'company_id' => $c, 'item_type' => 'product', 'code' => $code, 'name' => "Item {$code}", 'category_id' => $categoryId,
            'unit_id' => DB::table('units')->where('company_id', $c)->where('name', 'pcs')->value('id'), 'selling_price' => '100.00',
            'income_ledger_id' => DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_create_lists_the_tree_parent_first_with_counts_and_value(): void
    {
        $unit = DB::table('units')->where('company_id', $this->company->id)->where('name', 'jar')->value('id');
        $r = $this->req('POST', '/api/v1/product-categories', ['name' => ' Edible oils ', 'code_prefix' => 'OIL', 'default_unit_id' => $unit])->assertCreated();
        $r->assertJsonPath('data.name', 'Edible oils')->assertJsonPath('data.code_prefix', 'OIL')->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.items_count', 0)->assertJsonPath('data.stock_value', '0.00');
        $oils = $r->json('data.id');
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'product_categories')->where('auditable_id', $oils)->exists());
        $this->assertSame($unit, DB::table('product_categories')->where('id', $oils)->value('default_unit_id'));

        $rice = $this->category('Rice');
        $soy = $this->category('Soybean oil', $oils);
        $this->category('Mustard oil', $oils);
        $this->productIn($soy, 'P-1');
        $this->productIn($soy, 'P-2');

        $list = $this->req('GET', '/api/v1/product-categories')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame(['Edible oils', 'Mustard oil', 'Soybean oil', 'Rice'], array_column($list->json('data'), 'name'), 'parents first, children under them');
        $this->assertSame(2, collect($list->json('data'))->firstWhere('id', $soy)['items_count']);
        $this->assertSame($oils, collect($list->json('data'))->firstWhere('id', $soy)['parent_id']);

        $this->assertSame(['Edible oils', 'Mustard oil', 'Rice', 'Soybean oil'], array_column($this->req('GET', '/api/v1/product-categories?sort=name')->json('data'), 'name'));
        $this->req('GET', '/api/v1/product-categories?q=oil&per_page=2')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->assertSame([$rice], array_column($this->req('GET', '/api/v1/product-categories?q=rice')->json('data'), 'id'));
    }

    public function test_sort_order_controls_the_order_within_a_level(): void
    {
        $a = $this->category('Alpha');
        $b = $this->category('Beta');
        $this->req('PATCH', "/api/v1/product-categories/{$b}", ['sort_order' => 0])->assertOk();
        $this->req('PATCH', "/api/v1/product-categories/{$a}", ['sort_order' => 5])->assertOk()->assertJsonPath('data.name', 'Alpha');
        $this->assertSame(['Beta', 'Alpha'], array_column($this->req('GET', '/api/v1/product-categories')->json('data'), 'name'));
    }

    public function test_two_levels_at_most_and_no_cycles(): void
    {
        $oils = $this->category('Edible oils');
        $soy = $this->category('Soybean oil', $oils);
        $rice = $this->category('Rice');

        $deep = $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'Bottled', 'parent_id' => $soy]), 422, 'validation_failed');
        $this->assertSame(['Categories have at most 2 levels; choose a top-level parent'], $deep->json('errors.parent_id'));
        $self = $this->assertProblem($this->req('PATCH', "/api/v1/product-categories/{$oils}", ['parent_id' => $oils]), 422, 'validation_failed');
        $this->assertSame(['A category cannot be its own parent'], $self->json('errors.parent_id'));
        $cycle = $this->assertProblem($this->req('PATCH', "/api/v1/product-categories/{$oils}", ['parent_id' => $soy]), 422, 'validation_failed');
        $this->assertSame(['A category cannot move under one of its own sub-categories'], $cycle->json('errors.parent_id'));
        $withChildren = $this->assertProblem($this->req('PATCH', "/api/v1/product-categories/{$oils}", ['parent_id' => $rice]), 422, 'validation_failed');
        $this->assertSame(['This category has sub-categories, so it must stay at the top level'], $withChildren->json('errors.parent_id'));

        // Moving a leaf between parents and back to the top level is fine, and is audited.
        $this->req('PATCH', "/api/v1/product-categories/{$soy}", ['parent_id' => $rice])->assertOk()->assertJsonPath('data.parent_id', $rice);
        $this->req('PATCH', "/api/v1/product-categories/{$soy}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
        $this->assertSame(2, AuditLog::where('action', 'updated')->where('auditable_type', 'product_categories')->where('auditable_id', $soy)->count());
    }

    public function test_validation(): void
    {
        $this->category('Rice');
        $this->assertSame(['A category with this name already exists'],
            $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'rice']), 422, 'validation_failed')->json('errors.name'));
        $this->assertSame(['Enter a category name'],
            $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => '  ']), 422, 'validation_failed')->json('errors.name'));
        $this->assertSame(['Use 2 to 10 capital letters or digits, e.g. OIL'],
            $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'Spices', 'code_prefix' => 'sp']), 422, 'validation_failed')->json('errors.code_prefix'));
        $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'Spices', 'parent_id' => 999999]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'Spices', 'default_unit_id' => 999999]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'Spices', 'colour' => 'red']), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/product-categories?sort=items'), 422, 'validation_failed');

        // Renaming to its own name is not a duplicate; an empty PATCH changes nothing.
        $id = $this->category('Spices');
        $this->req('PATCH', "/api/v1/product-categories/{$id}", ['name' => 'Spices'])->assertOk();
        $this->req('PATCH', "/api/v1/product-categories/{$id}", [])->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'updated')->where('auditable_type', 'product_categories')->count());
    }

    public function test_delete_only_when_unused(): void
    {
        $oils = $this->category('Edible oils');
        $soy = $this->category('Soybean oil', $oils);
        $this->productIn($soy, 'P-1');

        $this->assertSame('This category has 1 product; move them to another one first.',
            $this->assertProblem($this->req('DELETE', "/api/v1/product-categories/{$soy}"), 409, 'has_history')->json('detail'));
        $this->assertSame('This category has sub-categories; move or delete them first.',
            $this->assertProblem($this->req('DELETE', "/api/v1/product-categories/{$oils}"), 409, 'has_history')->json('detail'));

        DB::table('products')->where('category_id', $soy)->update(['category_id' => null]);
        $this->req('DELETE', "/api/v1/product-categories/{$soy}")->assertNoContent();
        $this->req('DELETE', "/api/v1/product-categories/{$oils}")->assertNoContent();
        $this->assertSame(0, DB::table('product_categories')->where('company_id', $this->company->id)->count());
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('auditable_type', 'product_categories')->where('auditable_id', $oils)->exists());
        $this->assertProblem($this->req('DELETE', "/api/v1/product-categories/{$oils}"), 404, 'not_found');
    }

    public function test_permissions_cost_masking_and_isolation(): void
    {
        $id = $this->category('Rice');

        // Store keeper: may add and edit, not delete, and sees no stock value (no inventory.view_costs, BR-052).
        $store = $this->as('store');
        $this->req('POST', '/api/v1/product-categories', ['name' => 'Pulses'], $store)->assertCreated()->assertJsonMissingPath('data.stock_value');
        $this->req('PATCH', "/api/v1/product-categories/{$id}", ['code_prefix' => 'RIC'], $store)->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/product-categories/{$id}", token: $store), 403, 'permission_denied');
        $this->req('GET', '/api/v1/product-categories', token: $store)->assertOk()->assertJsonMissingPath('data.0.stock_value');

        // Viewer reads (with values), cannot change.
        $viewer = $this->as('viewer');
        $this->req('GET', '/api/v1/product-categories', token: $viewer)->assertOk()->assertJsonPath('data.0.stock_value', '0.00');
        $this->assertProblem($this->req('POST', '/api/v1/product-categories', ['name' => 'X'], $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('PATCH', "/api/v1/product-categories/{$id}", ['name' => 'X'], $viewer), 403, 'permission_denied');

        // Another company's category is not found and cannot be used as a parent.
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->api('GET', '/api/v1/product-categories', $ot, $other->id)->assertJsonPath('meta.total', 0);
        $this->assertProblem($this->api('PATCH', "/api/v1/product-categories/{$id}", $ot, $other->id, ['name' => 'Mine']), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', "/api/v1/product-categories/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/product-categories', $ot, $other->id, ['name' => 'Sub', 'parent_id' => $id]), 422, 'validation_failed');
        $this->api('POST', '/api/v1/product-categories', $ot, $other->id, ['name' => 'Rice'])->assertCreated();
    }
}
