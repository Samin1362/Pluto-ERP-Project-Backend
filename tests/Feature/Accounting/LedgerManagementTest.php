<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /ledgers, GET/PATCH /ledgers/{id}, POST /ledgers/{id}/deactivate (H4/H5; BR-026–BR-028; backlog P7.1-BE-02). */
class LedgerManagementTest extends TestCase
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
        if ($method === 'POST' && $uri === '/api/v1/ledgers') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
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

    private function journal(int $debit, int $credit, string $amount): void
    {
        $this->useBooks();
        app(PostingService::class)->persist((new JournalBuilder)->debit($debit, Money::of($amount))->credit($credit, Money::of($amount)),
            null, 'journal', CarbonImmutable::now('Asia/Dhaka'), null, 'Test journal');
    }

    private function fyStart(): string
    {
        $this->useBooks();
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();

        return FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->value('start_date')->toDateString();
    }

    public function test_create_with_an_automatic_code_and_an_opening_balance(): void
    {
        $r = $this->req('POST', '/api/v1/ledgers', ['name' => 'Security deposit, office', 'account_group_id' => $this->group('11'),
            'description' => 'Paid to the landlord', 'opening_balance' => '60000.00', 'opening_side' => 'dr'])->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.code', '1101')->assertJsonPath('data.nature', 'asset')->assertJsonPath('data.ledger_kind', 'general')
            ->assertJsonPath('data.group_path', ['Assets', 'Current assets'])->assertJsonPath('data.is_system', false)->assertJsonPath('data.has_entries', true)
            ->assertJsonPath('data.opening_balance', '60000.00')->assertJsonPath('data.opening_side', 'dr')->assertJsonPath('data.balance', '60000.00')
            ->assertJsonPath('data.fy_summary.opening', '0.00')->assertJsonPath('data.fy_summary.debit', '60000.00')->assertJsonPath('data.fy_summary.closing', '60000.00');
        $this->assertStringEndsWith("/api/v1/ledgers/{$r->json('data.id')}", $r->headers->get('Location'));
        $this->useBooks();
        $entry = JournalEntry::with('lines')->where('source_type', 'ledgers')->where('source_id', $r->json('data.id'))->sole();
        $this->assertSame(['opening_balance', $this->fyStart()], [$entry->entry_type, $entry->entry_date->toDateString()]);
        $this->assertSame([[$r->json('data.id'), '60000.00', '0.00'], [$this->ledger('3301'), '0.00', '60000.00']],
            $entry->lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->all());
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'ledgers')->where('auditable_id', $r->json('data.id'))->exists());

        $loan = $this->req('POST', '/api/v1/ledgers', ['name' => 'Loan from director', 'account_group_id' => $this->group('22'), 'code' => '2401',
            'opening_balance' => '200000.00', 'opening_side' => 'cr'])->assertCreated();
        $loan->assertJsonPath('data.code', '2401')->assertJsonPath('data.opening_side', 'cr')->assertJsonPath('data.balance_side', 'cr');
        $this->req('POST', '/api/v1/ledgers', ['name' => 'Petty advances', 'account_group_id' => $this->group('11')])->assertCreated()
            ->assertJsonPath('data.code', '1102')->assertJsonPath('data.has_entries', false)->assertJsonPath('data.balance', '0.00');
    }

    public function test_validation(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', []), 422, 'validation_failed')->assertJsonStructure(['errors' => ['name', 'account_group_id']]);
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61'), 'code' => '6101']), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Another ledger already uses this code');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61'), 'code' => '4999']), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Expenses ledgers have codes starting with 6');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61'), 'opening_balance' => '-5']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61'), 'opening_side' => 'left']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61'), 'ledger_kind' => 'system']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => 999999]), 422, 'validation_failed');
        $this->assertSame(0, Ledger::where('name', 'X')->count());
    }

    public function test_t_c_ac_c_013_code_and_opening_lock_after_entries_and_regroup_stays_in_the_nature(): void
    {
        $id = $this->req('POST', '/api/v1/ledgers', ['name' => 'Generator fuel', 'account_group_id' => $this->group('61'), 'code' => '6120'])->json('data.id');
        $url = "/api/v1/ledgers/{$id}";
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Fuel']), 428, 'if_match_required');
        $this->req('PATCH', $url, ['name' => 'Generator fuel and oil', 'code' => '6121', 'account_group_id' => $this->group('62'), 'opening_balance' => '1500.00'],
            ['If-Match' => 'W/"1"'])->assertOk()->assertHeader('ETag', 'W/"2"')->assertJsonPath('data.code', '6121')
            ->assertJsonPath('data.account_group_id', $this->group('62'))->assertJsonPath('data.opening_balance', '1500.00');
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Y'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', $url, ['account_group_id' => $this->group('41')], ['If-Match' => 'W/"2"']), 422, 'nature_mismatch');

        $this->journal($id, $this->ledger('1101-01'), '800'); // the cash account has no money account yet, any general entry will do
        $this->assertProblem($this->req('PATCH', $url, ['code' => '6122'], ['If-Match' => 'W/"2"']), 422, 'ledger_locked');
        $this->assertProblem($this->req('PATCH', $url, ['opening_balance' => '2000.00'], ['If-Match' => 'W/"2"']), 422, 'ledger_locked');
        $this->req('PATCH', $url, ['name' => 'Fuel', 'opening_balance' => '1500.00', 'code' => '6121', 'account_group_id' => $this->group('61')], ['If-Match' => 'W/"2"'])
            ->assertOk()->assertJsonPath('data.name', 'Fuel')->assertJsonPath('data.account_group_id', $this->group('61'))->assertJsonPath('data.balance', '2300.00');
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'ledgers')->where('auditable_id', $id)->exists());
    }

    public function test_master_ledgers_are_edited_through_their_masters(): void
    {
        $party = $this->customer();
        $this->assertProblem($this->req('PATCH', "/api/v1/ledgers/{$party->ledger_id}", ['name' => 'X'], ['If-Match' => 'W/"1"']), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'This ledger belongs to a customer or supplier; change it from that customer or supplier');
        $this->assertProblem($this->req('POST', "/api/v1/ledgers/{$party->ledger_id}/deactivate"), 409, 'has_history');
        $this->req('GET', "/api/v1/ledgers/{$party->ledger_id}")->assertOk()->assertJsonPath('data.ledger_kind', 'party');
        $this->assertProblem($this->req('PATCH', '/api/v1/ledgers/'.$this->ledger('3301'), ['opening_balance' => '10.00'], ['If-Match' => 'W/"1"']), 422, 'validation_failed')
            ->assertJsonPath('errors.opening_balance.0', 'The opening balance adjustment ledger cannot have its own opening balance');
    }

    public function test_b_r_026_deactivate_needs_a_zero_balance_and_no_posting_rule(): void
    {
        $id = $this->req('POST', '/api/v1/ledgers', ['name' => 'Generator fuel', 'account_group_id' => $this->group('61'), 'opening_balance' => '500.00'])->json('data.id');
        $this->assertProblem($this->req('POST', "/api/v1/ledgers/{$id}/deactivate"), 409, 'has_history')
            ->assertJsonPath('detail', 'The ledger still has a balance of 500.00 Dr; clear it with a journal first.');
        $this->journal($this->ledger('1101-01'), $id, '500');
        $this->req('POST', "/api/v1/ledgers/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 2);
        $this->req('POST', "/api/v1/ledgers/{$id}/deactivate")->assertOk()->assertJsonPath('data.version', 2);
        $this->assertTrue(AuditLog::where('action', 'deactivated')->where('auditable_type', 'ledgers')->where('auditable_id', $id)->exists());
        $this->assertProblem($this->req('POST', '/api/v1/ledgers/'.$this->ledger('6799').'/deactivate'), 409, 'has_history');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers/999999/deactivate'), 404, 'not_found');
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/ledgers/'.$this->ledger('6101'), token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/ledgers', ['name' => 'X', 'account_group_id' => $this->group('61')], token: $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', '/api/v1/ledgers/'.$this->ledger('6101').'/deactivate', token: $viewer), 403, 'permission_denied');
        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', '/api/v1/ledgers/'.$this->ledger('6101'), token: $this->login('sales@meghna.example')), 403, 'permission_denied');

        $id = $this->ledger('6101');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/ledgers/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', "/api/v1/ledgers/{$id}", $ot, $other->id, ['name' => 'X'], ['If-Match' => 'W/"1"']), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/ledgers/{$id}/deactivate", $ot, $other->id), 404, 'not_found');
    }

    public function test_idempotent_create(): void
    {
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['name' => 'Generator fuel', 'account_group_id' => $this->group('61')];
        $a = $this->api('POST', '/api/v1/ledgers', $this->token, $this->books->id, $body, $key)->assertCreated();
        $b = $this->api('POST', '/api/v1/ledgers', $this->token, $this->books->id, $body, $key)->assertCreated();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->useBooks();
        $this->assertSame(1, Ledger::where('name', 'Generator fuel')->count());
    }
}
