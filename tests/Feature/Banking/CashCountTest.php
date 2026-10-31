<?php

namespace Tests\Feature\Banking;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Cash counts: /cash-counts (G2; 06 §13; BR-058, BR-003; R-19; TC-BNK-006, TC-BNK-010; backlog P12.1-BE-03). */
class CashCountTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private array $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->cash = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash', 'opening_balance' => '12500.00'])->assertCreated()->json('data');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function countCash(array $notes, ?string $token = null)
    {
        return $this->req('POST', '/api/v1/cash-counts', ['money_account_id' => $this->cash['id'], 'counted_at' => now('Asia/Dhaka')->toIso8601String(), 'note' => 'Evening count',
            'denominations' => array_map(fn ($d, $n) => ['denomination' => (string) $d, 'count' => $n], array_keys($notes), $notes)], $token);
    }

    /** Approves as the Finance manager: the counter may not approve their own adjustment. */
    private function approve(int $requestId): void
    {
        $this->userWithRole('finance', 'finance@meghna.example');
        $this->req('POST', "/api/v1/approvals/{$requestId}/approve", ['comment' => 'Checked the drawer'], $this->login('finance@meghna.example'))->assertOk();
    }

    private function balance(): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $this->cash['ledger_id'])->where('is_posted', 1)->selectRaw('SUM(debit) - SUM(credit) b')->value('b');
    }

    public function test_a_count_that_agrees_records_nothing_to_post(): void
    {
        $r = $this->countCash([1000 => 12, 500 => 1])->assertCreated();
        $r->assertJsonPath('data.counted_total', '12500.00')->assertJsonPath('data.book_balance', '12500.00')->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.adjustment_entry_id', null)->assertJsonPath('data.note', 'Evening count')
            ->assertJsonPath('data.denominations.0.value', '12000.00');
    }

    public function test_tc_bnk_006_a_shortage_of_500_waits_for_approval_then_posts(): void
    {
        $r = $this->countCash([1000 => 12])->assertCreated();
        $r->assertJsonPath('data.difference', '-500.00')->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.adjustment_status', 'submitted');
        $entry = JournalEntry::with('lines.ledger')->findOrFail($r->json('data.adjustment_entry_id'));
        $this->assertSame([['6198', '500.00', '0.00'], [DB::table('ledgers')->where('id', $this->cash['ledger_id'])->value('code'), '0.00', '500.00']],
            $entry->lines->map(fn ($l) => [$l->ledger->code, (string) $l->debit, (string) $l->credit])->all());
        $this->assertSame('12500.00', $this->balance(), 'not posted before approval (BR-058)');

        $this->approve($r->json('data.approval_request_id'));
        $this->assertSame('12000.00', $this->balance());
        $this->assertSame('posted', JournalEntry::find($entry->id)->status);
    }

    public function test_tc_bnk_010_reversing_a_posted_adjustment_restores_the_book(): void
    {
        $r = $this->countCash([1000 => 13]);
        $r->assertJsonPath('data.difference', '500.00');
        $this->approve($r->json('data.approval_request_id'));
        $this->assertSame('13000.00', $this->balance());
        $id = $r->json('data.id');
        $this->assertProblem($this->req('POST', "/api/v1/cash-counts/{$id}/reverse", []), 422, 'validation_failed');
        $this->req('POST', "/api/v1/cash-counts/{$id}/reverse", ['reason' => 'Counted a note twice'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame('12500.00', $this->balance());
        $this->assertSame('reversed', JournalEntry::find($r->json('data.adjustment_entry_id'))->status);
        $this->assertSame(1, JournalEntry::where('reverses_entry_id', $r->json('data.adjustment_entry_id'))->where('is_system_generated', true)->count());
        $this->assertProblem($this->req('POST', "/api/v1/cash-counts/{$id}/reverse", ['reason' => 'Again']), 409, 'already_reversed');

        // A pending one is withdrawn instead: nothing ever posts
        $p = $this->countCash([500 => 24]);
        $this->req('POST', '/api/v1/cash-counts/'.$p->json('data.id').'/reverse', ['reason' => 'Recounting'])->assertOk();
        $this->assertSame('12500.00', $this->balance());
        $this->assertSame('rejected', JournalEntry::find($p->json('data.adjustment_entry_id'))->status);
    }

    public function test_validation_and_permissions(): void
    {
        $bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna', 'account_number' => '1'])->json('data');
        $e = $this->assertProblem($this->req('POST', '/api/v1/cash-counts', ['money_account_id' => $bank['id'], 'counted_at' => now()->toIso8601String(),
            'denominations' => [['denomination' => '1000', 'count' => 1]]]), 422, 'validation_failed');
        $this->assertStringContainsString('not a cash account', $e->json('errors.money_account_id.0'));
        $this->assertProblem($this->req('POST', '/api/v1/cash-counts', ['money_account_id' => $this->cash['id'], 'counted_at' => now()->toIso8601String(),
            'denominations' => [['denomination' => '1000', 'count' => -1]]]), 422, 'validation_failed');
        $this->userWithRole('accountant', 'acc@meghna.example');
        $acc = $this->login('acc@meghna.example');
        $id = $this->countCash([1000 => 12], $acc)->assertCreated()->json('data.id');
        $this->assertProblem($this->req('POST', "/api/v1/cash-counts/{$id}/reverse", ['reason' => 'x'], $acc), 403, 'permission_denied');
    }
}
