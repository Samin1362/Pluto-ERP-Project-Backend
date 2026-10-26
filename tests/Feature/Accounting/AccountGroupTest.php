<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** /account-groups (H2/H3; BR-027; backlog P7.1-BE-01): chart tree with balances, group details, create and edit. */
class AccountGroupTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function group(string $code): int
    {
        $this->useBooks();

        return (int) AccountGroup::where('code', $code)->value('id');
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    /** Dr cash 50,000 / Cr share capital 50,000 on $date, then Dr rent 12,000 / Cr cash 12,000. */
    private function postEntries(?string $date = null): void
    {
        $this->useBooks();
        $date = CarbonImmutable::parse($date ?? CarbonImmutable::now('Asia/Dhaka')->toDateString());
        $posting = app(PostingService::class);
        $posting->persist((new JournalBuilder)->debit($this->ledger('1101-01'), Money::of('50000'))->credit($this->ledger('3101'), Money::of('50000')),
            null, 'journal', $date, null, 'Capital introduced');
        $posting->persist((new JournalBuilder)->debit($this->ledger('6101'), Money::of('12000'))->credit($this->ledger('1101-01'), Money::of('12000')),
            null, 'journal', $date, null, 'Rent');
    }

    /** @return array<string, array> every node of the tree by code */
    private function flatten(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[$n['code']] = $n;
            $out += $this->flatten($n['children'] ?? []);
        }

        return $out;
    }

    public function test_t_c_ac_c_013_the_tree_rolls_ledger_balances_up_to_the_primary_groups(): void
    {
        $this->postEntries();
        $r = $this->req('GET', '/api/v1/account-groups')->assertOk();
        $r->assertJsonPath('data.as_of', CarbonImmutable::now()->toDateString())->assertJsonPath('data.total_debit', '50000.00')
            ->assertJsonPath('data.total_credit', '50000.00')->assertJsonPath('data.balanced', true);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_column($r->json('data.nodes'), 'code'));
        $n = $this->flatten($r->json('data.nodes'));
        $this->assertSame(['primary', '38000.00', 'dr', 'asset'], [$n['1']['kind'], $n['1']['balance'], $n['1']['balance_side'], $n['1']['nature']]);
        $this->assertSame(['group', '38000.00', 'dr'], [$n['1100']['kind'], $n['1100']['balance'], $n['1100']['balance_side']]);
        $this->assertSame(['ledger', '38000.00', 'dr', 'asset'], [$n['1101-01']['kind'], $n['1101-01']['balance'], $n['1101-01']['balance_side'], $n['1101-01']['nature']]);
        $this->assertSame(['50000.00', 'cr'], [$n['3']['balance'], $n['3']['balance_side']]);
        $this->assertSame(['12000.00', 'dr'], [$n['6']['balance'], $n['6']['balance_side']]);
        $this->assertSame(['0.00', 'dr'], [$n['2']['balance'], $n['2']['balance_side']]);

        $groupsOnly = $this->flatten($this->req('GET', '/api/v1/account-groups?with_ledgers=false')->json('data.nodes'));
        $this->assertArrayNotHasKey('1101-01', $groupsOnly);
        $nonZero = $this->flatten($this->req('GET', '/api/v1/account-groups?zero=false')->json('data.nodes'));
        $this->assertSame(['1', '11', '1100', '1101-01', '3', '3101', '6', '61', '6101'], array_column($nonZero, 'code'), 'zero=false hides empty groups and ledgers');
    }

    public function test_as_of_counts_only_entries_up_to_that_date(): void
    {
        $this->postEntries(CarbonImmutable::now('Asia/Dhaka')->toDateString());
        $yesterday = CarbonImmutable::now('Asia/Dhaka')->subDay()->toDateString();
        $this->req('GET', "/api/v1/account-groups?as_of={$yesterday}")->assertOk()->assertJsonPath('data.as_of', $yesterday)
            ->assertJsonPath('data.total_debit', '0.00')->assertJsonPath('data.balanced', true);
        $this->assertProblem($this->req('GET', '/api/v1/account-groups?as_of=25-10-2026'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/account-groups?zero=yes'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/account-groups?colour=red'), 422, 'validation_failed');
    }

    public function test_a_new_group_takes_its_nature_from_the_parent(): void
    {
        $r = $this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'Office running costs', 'parent_id' => $this->group('61'),
            'cash_flow_category' => 'operating', 'sort_order' => 5, 'used_in_cash_vouchers' => true])->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.code', '6103')->assertJsonPath('data.parent_id', $this->group('61'))->assertJsonPath('data.nature', 'expense')
            ->assertJsonPath('data.normal_balance', AccountGroup::find($this->group('6'))->normal_balance)
            ->assertJsonPath('data.report_section', AccountGroup::find($this->group('6'))->report_section)
            ->assertJsonPath('data.cash_flow_category', 'operating')->assertJsonPath('data.is_system', false)->assertJsonPath('data.used_in_cash_vouchers', true)
            ->assertJsonPath('data.sort_order', 5)->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.children', [])->assertJsonPath('data.version', 1);
        $this->assertStringEndsWith("/api/v1/account-groups/{$r->json('data.id')}", $r->headers->get('Location'));
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'account_groups')->where('auditable_id', $r->json('data.id'))->exists());
        $n = $this->flatten($this->req('GET', '/api/v1/account-groups')->json('data.nodes'));
        $this->assertSame(['group', 'Added by you'], [$n['6103']['kind'], $n['6103']['note']]);
    }

    public function test_validation(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', []), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['code', 'name', 'parent_id']]);
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => '61', 'name' => 'Again', 'parent_id' => $this->group('6')]), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Another group already uses this code');
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'X', 'parent_id' => $this->group('6'), 'cash_flow_category' => 'misc']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'X', 'parent_id' => $this->group('6'), 'nature' => 'income']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => str_repeat('9', 21), 'name' => 'X', 'parent_id' => $this->group('6')]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'X', 'parent_id' => 999999]), 422, 'validation_failed')
            ->assertJsonPath('errors.parent_id.0', 'Choose a group of this company');
    }

    public function test_details_list_child_groups_and_ledgers(): void
    {
        $this->postEntries();
        $r = $this->req('GET', '/api/v1/account-groups/'.$this->group('61'))->assertOk()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.code', '61')->assertJsonPath('data.is_system', true)->assertJsonPath('data.balance', '12000.00')->assertJsonPath('data.balance_side', 'dr');
        $rent = collect($r->json('data.children'))->firstWhere('code', '6101');
        $this->assertSame(['ledger', '12000.00', 'dr'], [$rent['kind'], $rent['balance'], $rent['balance_side']]);
        $this->assertProblem($this->req('GET', '/api/v1/account-groups/999999'), 404, 'not_found');
    }

    public function test_b_r_027_edit_within_the_same_nature_and_system_groups_locked(): void
    {
        $id = $this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'Office running costs', 'parent_id' => $this->group('61')])->json('data.id');
        $url = "/api/v1/account-groups/{$id}";
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Office costs']), 428, 'if_match_required');
        $this->req('PATCH', $url, ['name' => 'Office costs', 'parent_id' => $this->group('62')], ['If-Match' => 'W/"1"'])->assertOk()
            ->assertHeader('ETag', 'W/"2"')->assertJsonPath('data.name', 'Office costs')->assertJsonPath('data.parent_id', $this->group('62'))->assertJsonPath('data.version', 2);
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Y'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', $url, ['parent_id' => $this->group('41')], ['If-Match' => 'W/"2"']), 422, 'nature_mismatch');
        $child = $this->req('POST', '/api/v1/account-groups', ['code' => '6103.1', 'name' => 'Stationery', 'parent_id' => $id])->json('data.id');
        $this->assertProblem($this->req('PATCH', $url, ['parent_id' => $child], ['If-Match' => 'W/"2"']), 422, 'validation_failed')
            ->assertJsonPath('errors.parent_id.0', 'A group cannot move under itself or its own sub-groups');
        $this->assertProblem($this->req('PATCH', $url, ['code' => '62'], ['If-Match' => 'W/"2"']), 422, 'validation_failed');
        $this->req('PATCH', $url, ['code' => '6104'], ['If-Match' => 'W/"2"'])->assertOk()->assertJsonPath('data.code', '6104');
        $this->assertProblem($this->req('PATCH', '/api/v1/account-groups/'.$this->group('61'), ['name' => 'Mine'], ['If-Match' => 'W/"1"']), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Built-in groups cannot be changed; add a sub-group instead');
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'account_groups')->where('auditable_id', $id)->exists());

        // Once a ledger under the group has entries its code is locked; the name can still change.
        Ledger::whereKey($this->ledger('6101'))->update(['account_group_id' => $id]);
        $this->postEntries();
        $this->assertProblem($this->req('PATCH', $url, ['code' => '6105'], ['If-Match' => 'W/"3"']), 422, 'ledger_locked');
        $this->req('PATCH', $url, ['name' => 'Rent and office'], ['If-Match' => 'W/"3"'])->assertOk();
    }

    public function test_permissions(): void
    {
        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/account-groups', token: $sales), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', '/api/v1/account-groups/'.$this->group('61'), token: $sales), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/account-groups', token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'X', 'parent_id' => $this->group('61')], token: $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('PATCH', '/api/v1/account-groups/'.$this->group('61'), ['name' => 'X'], ['If-Match' => 'W/"1"'], $viewer), 403, 'permission_denied');
        $this->userWithRole('accountant');
        $this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'X', 'parent_id' => $this->group('61')], token: $this->login('accountant@meghna.example'))->assertCreated();
    }

    public function test_isolation(): void
    {
        $id = $this->req('POST', '/api/v1/account-groups', ['code' => '6103', 'name' => 'Office running costs', 'parent_id' => $this->group('61')])->json('data.id');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/account-groups/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', "/api/v1/account-groups/{$id}", $ot, $other->id, ['name' => 'X'], ['If-Match' => 'W/"1"']), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/account-groups', $ot, $other->id, ['code' => '6199', 'name' => 'X', 'parent_id' => $id]), 422, 'validation_failed');
        $codes = array_column($this->flatten($this->api('GET', '/api/v1/account-groups', $ot, $other->id)->json('data.nodes')), 'code');
        $this->assertNotContains('6103', $codes);
    }
}
