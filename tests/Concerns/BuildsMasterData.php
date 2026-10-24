<?php

namespace Tests\Concerns;

use App\Domain\Inventory\ProductService;
use App\Domain\Inventory\WarehouseService;
use App\Domain\Parties\PartyService;
use App\Models\Company;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Master data for posting tests (backlog P4.1-BE-07), created through the domain services so the rows are what the
 * API would make: a provisioned company set as the current one, suppliers, customers, warehouses and products.
 */
trait BuildsMasterData
{
    protected ?Company $books = null;

    /** A fully provisioned company (chart, FY, sequences, units) made current for the domain services. */
    protected function provisionedCompany(string $name = 'Meghna Traders Ltd.', string $ownerEmail = 'owner@meghna.example'): Company
    {
        $this->books = $this->makeCompany($name, $this->makeUser($ownerEmail));
        app(CurrentCompany::class)->set($this->books, null);

        return $this->books;
    }

    /** The current company is cleared at the end of every API request (middleware), so helpers set it again. */
    protected function useBooks(): void
    {
        app(CurrentCompany::class)->set($this->books, null);
    }

    protected function supplier(string $name = 'Bengal Edible Oils', string $mobile = '01911222333'): Party
    {
        $this->useBooks();

        return app(PartyService::class)->create(['party_type' => 'supplier', 'name' => $name, 'mobile' => $mobile, 'default_currency_code' => 'BDT'])['party'];
    }

    protected function customer(string $name = 'Karim Distributors', string $mobile = '01711458230'): Party
    {
        $this->useBooks();

        return app(PartyService::class)->create(['party_type' => 'customer', 'name' => $name, 'mobile' => $mobile, 'default_currency_code' => 'BDT'])['party'];
    }

    protected function warehouse(string $code = 'WH-TEJ', string $name = 'Tejgaon warehouse'): Warehouse
    {
        $this->useBooks();

        return app(WarehouseService::class)->create(['code' => $code, 'name' => $name, 'branch_id' => DB::table('branches')->where('company_id', $this->books->id)->value('id')]);
    }

    /** A stock item (or `service`), in `unit`, selling at `price`, posting income to 4101 (4201 for a service). */
    protected function product(string $name = 'Soybean oil 5L jar', string $unit = 'jar', string $price = '920.00', string $type = 'product'): Product
    {
        $this->useBooks();
        $c = $this->books->id;

        return app(ProductService::class)->create(['item_type' => $type, 'name' => $name, 'selling_price' => $price,
            'unit_id' => DB::table('units')->where('company_id', $c)->where('name', $unit)->value('id'),
            'income_ledger_id' => DB::table('ledgers')->where('company_id', $c)->where('code', $type === 'service' ? '4201' : '4101')->value('id')]);
    }

    /** A user with a role in the current company (for auth-dependent services and API calls). */
    protected function userWithRole(string $role, ?string $email = null): User
    {
        $this->useBooks();
        $user = $this->makeUser($email ?? "{$role}@meghna.example");
        $this->addMember($this->books, $user, $role);

        return $user;
    }
}
