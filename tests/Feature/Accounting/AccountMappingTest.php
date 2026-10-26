<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountMapper;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Ledger;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** /account-mappings (K16 Accounting defaults; 06 §3; TC-ACC-024; backlog P7.1-BE-08). */
class AccountMappingTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    public function test_the_list_shows_every_role_with_its_required_nature(): void
    {
        $r = $this->req('GET', '/api/v1/account-mappings')->assertOk();
        $this->assertNotNull($r->headers->get('ETag'));
        $rows = collect($r->json('data.mappings'))->keyBy('mapping_key');
        $this->assertCount(23, $rows);
        $this->assertSame(['ledger_id' => $this->ledger('5001'), 'account_group_id' => null, 'required_nature' => 'expense'],
            array_intersect_key($rows['cogs'], array_flip(['ledger_id', 'account_group_id', 'required_nature'])));
        $this->assertSame('asset group', $rows['cash_bank_group']['required_nature']);
        $this->assertSame('income or expense', $rows['discount_allowed']['required_nature']);
        $this->assertProblem($this->req('GET', '/api/v1/account-mappings?x=1'), 422, 'validation_failed');
    }

    public function test_t_c_ac_c_024_saving_checks_the_nature_of_each_role(): void
    {
        $this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'sales_default', 'ledger_id' => $this->ledger('4102')],
            ['mapping_key' => 'cogs', 'ledger_id' => $this->ledger('5001')]]])->assertOk();
        $this->useBooks();
        $this->assertSame($this->ledger('4102'), app(AccountMapper::class)->ledgerFor('sales_default')->id);
        $this->assertSame(1, AuditLog::where('auditable_type', 'account_mappings')->count(), 'only the changed role is audited');

        $e = $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'cogs', 'ledger_id' => $this->ledger('4101')]]]), 422, 'validation_failed')
            ->json('errors');
        $this->assertSame('Sales, wholesale is income; cogs needs expense', $e['mappings.0.ledger_id'][0]);
        $group = AccountGroup::where('code', '21')->value('id');
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'cash_bank_group', 'account_group_id' => $group]]]), 422, 'validation_failed');
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'cogs', 'account_group_id' => $group]]]), 422, 'validation_failed');
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'tea_money', 'ledger_id' => $this->ledger('6199')]]]), 422, 'validation_failed');
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'rounding', 'ledger_id' => null]]]), 422, 'mapping_missing');
        $this->useBooks();
        Ledger::whereKey($this->ledger('6199'))->update(['is_active' => false]);
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'rounding', 'ledger_id' => $this->ledger('6199')]]]), 422, 'validation_failed');
        $this->assertSame($this->ledger('5001'), app(AccountMapper::class)->ledgerFor('cogs')->id, 'nothing changed');
    }

    public function test_permissions(): void
    {
        $this->userWithRole('accountant');
        $accountant = $this->login('accountant@meghna.example');
        $this->req('GET', '/api/v1/account-mappings', token: $accountant)->assertOk();
        $this->assertProblem($this->req('PUT', '/api/v1/account-mappings', ['mappings' => [['mapping_key' => 'cogs', 'ledger_id' => $this->ledger('5001')]]], $accountant), 403, 'permission_denied');
        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', '/api/v1/account-mappings', token: $this->login('sales@meghna.example')), 403, 'permission_denied');
    }
}
