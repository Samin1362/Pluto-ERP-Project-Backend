<?php

namespace Tests\Feature\Approvals;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\Permission;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** /approvals and /notifications (L3/L4; BR-007, BR-008; TC-ACC-009/010/011, TC-AUTH-019; backlog P7.1-BE-05). */
class ApprovalTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $owner;

    private string $accountant;

    private string $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->owner = $this->login('owner@meghna.example');
        $this->userWithRole('accountant');
        $this->accountant = $this->login('accountant@meghna.example');
        $this->userWithRole('finance');
        $this->finance = $this->login('finance@meghna.example');
    }

    private function req(string $method, string $uri, string $token, array $data = [])
    {
        $headers = $method === 'POST' && (str_ends_with($uri, '/approve') || $uri === '/api/v1/vouchers') ? ['Idempotency-Key' => (string) Str::uuid()] : [];
        $r = $this->api($method, $uri, $token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    private function balance(string $code): string
    {
        $this->useBooks();

        return (string) DB::table('journal_lines')->where('ledger_id', $this->ledger($code))->where('is_posted', 1)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b');
    }

    /** An Accountant journal of ৳ 1,50,000, above their ৳ 1,00,000 limit: 202 and a pending request. */
    private function submitted(): array
    {
        $r = $this->req('POST', '/api/v1/vouchers', $this->accountant, ['entry_type' => 'journal', 'action' => 'post', 'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'narration' => 'Year-end depreciation', 'lines' => [['ledger_id' => $this->ledger('6501'), 'debit' => '150000.00'], ['ledger_id' => $this->ledger('1509'), 'credit' => '150000.00']]]);
        $r->assertStatus(202)->assertJsonPath('data.status', 'submitted');

        return $r->json('data');
    }

    public function test_t_c_ac_c_009_approvers_are_notified_and_approving_posts(): void
    {
        $v = $this->submitted();
        $approvalId = $v['approval']['id'];
        $this->assertSame('0.00', $this->balance('6501'));

        $bell = $this->req('GET', '/api/v1/notifications?unread=true', $this->finance)->assertOk();
        $bell->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.category', 'approvals')->assertJsonPath('data.0.link_type', 'approval_requests')
            ->assertJsonPath('data.0.link_id', $approvalId)->assertJsonPath('data.0.title', "{$v['voucher_number']} waits for your approval");
        $this->assertSame(1, $this->req('GET', '/api/v1/notifications', $this->owner)->json('meta.total'));
        $this->assertSame(0, $this->req('GET', '/api/v1/notifications', $this->accountant)->json('meta.total'), 'the requester is not asked');

        $list = $this->req('GET', '/api/v1/approvals', $this->finance)->assertOk();
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_number', $v['voucher_number'])->assertJsonPath('data.0.limit', '100000.00')
            ->assertJsonPath('data.0.facts.lines.0.debit', '150000.00');
        $this->assertSame(1, $this->req('GET', '/api/v1/approvals', $this->accountant)->json('meta.total'), 'the requester sees their own');

        $a = $this->req('POST', "/api/v1/approvals/{$approvalId}/approve", $this->finance, ['comment' => 'Agreed with the asset register'])->assertOk();
        $a->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.name', 'Finance')->assertJsonPath('data.comment', 'Agreed with the asset register');
        $this->assertSame('150000.00', $this->balance('6501'));
        $this->useBooks();
        $entry = JournalEntry::findOrFail($v['id']);
        $this->assertSame(['posted', $v['voucher_number']], [$entry->status, $entry->voucher_number]);
        $this->assertNotNull($entry->approved_at);
        $this->assertTrue(AuditLog::where('action', 'approved')->where('auditable_id', $v['id'])->exists());
        $this->req('GET', '/api/v1/notifications', $this->accountant)->assertJsonPath('data.0.event_key', 'approval_approved');
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$approvalId}/approve", $this->owner), 409, 'not_draft');
        $this->assertSame(0, $this->req('GET', '/api/v1/approvals', $this->finance)->json('meta.total'), 'nothing pending');
    }

    public function test_t_c_ac_c_010_nobody_approves_their_own_voucher(): void
    {
        $this->userWithRole('accountant', 'accountant2@meghna.example');
        $v = $this->submitted();
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$v['approval']['id']}/approve", $this->accountant), 403, 'self_approval_forbidden');
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$v['approval']['id']}/reject", $this->accountant, ['comment' => 'No']), 403, 'self_approval_forbidden');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$v['approval']['id']}/approve", $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', "/api/v1/approvals/{$v['approval']['id']}", $viewer), 403, 'permission_denied');
        $this->req('GET', "/api/v1/approvals/{$v['approval']['id']}", $this->accountant)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('0.00', $this->balance('6501'));
    }

    public function test_t_c_ac_c_011_reject_needs_a_comment_and_sends_the_voucher_back(): void
    {
        $v = $this->submitted();
        $id = $v['approval']['id'];
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$id}/reject", $this->finance), 422, 'comment_required');
        $this->req('POST', "/api/v1/approvals/{$id}/reject", $this->finance, ['comment' => 'Split it by asset'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.comment', 'Split it by asset');
        $this->req('GET', "/api/v1/vouchers/{$v['id']}", $this->accountant)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.approval.status', 'rejected');
        $this->req('GET', '/api/v1/notifications', $this->accountant)->assertJsonPath('data.0.event_key', 'approval_rejected')->assertJsonPath('data.0.body', 'Split it by asset');

        // The author fixes it and submits again; a fresh request is opened.
        $version = $this->req('GET', "/api/v1/vouchers/{$v['id']}", $this->accountant)->json('data.version');
        $this->api('PUT', "/api/v1/vouchers/{$v['id']}", $this->accountant, $this->books->id, ['entry_type' => 'journal', 'action' => 'submit',
            'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'lines' => [['ledger_id' => $this->ledger('6501'), 'debit' => '120000.00'],
                ['ledger_id' => $this->ledger('1509'), 'credit' => '120000.00']]], ['If-Match' => "W/\"{$version}\""])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->useBooks();
        $this->assertSame(['rejected', 'pending'], DB::table('approval_requests')->where('document_id', $v['id'])->orderBy('id')->pluck('status')->all());
    }

    public function test_approving_into_a_locked_period_is_refused(): void
    {
        $v = $this->submitted();
        $this->useBooks();
        $period = AccountingPeriod::whereDate('start_date', '<=', now('Asia/Dhaka')->toDateString())->whereDate('end_date', '>=', now('Asia/Dhaka')->toDateString())->firstOrFail();
        $period->forceFill(['status' => 'locked'])->save();
        $this->assertProblem($this->req('POST', "/api/v1/approvals/{$v['approval']['id']}/approve", $this->finance), 422, 'period_locked')->assertJsonPath('period', $period->name);
        $this->useBooks();
        $this->assertSame('submitted', JournalEntry::findOrFail($v['id'])->status, 'rolled back');
        $this->assertSame('pending', DB::table('approval_requests')->where('id', $v['approval']['id'])->value('status'));
    }

    public function test_t_c_aut_h_019_zero_limits_send_every_payment_for_approval_and_finance_has_no_journal_limit(): void
    {
        $this->useBooks();
        $this->assertSame('9999999999999999.99', Role::where('slug', 'finance')->value('journal_approval_limit'), 'owner decision D8');
        $role = Role::create(['name' => 'Cashier', 'slug' => 'cashier', 'is_system' => false, 'payment_approval_limit' => '0.00', 'journal_approval_limit' => '0.00']);
        $role->permissions()->sync(Permission::whereIn('key', ['banking.create', 'banking.view'])->pluck('id')->all());
        $this->addMember($this->books, $this->makeUser('cashier@meghna.example'), 'cashier');
        $cash = $this->api('POST', '/api/v1/money-accounts', $this->owner, $this->books->id, ['account_type' => 'cash', 'opening_balance' => '5000.00'],
            ['Idempotency-Key' => (string) Str::uuid()])->json('data');
        $r = $this->req('POST', '/api/v1/vouchers', $this->login('cashier@meghna.example'), ['entry_type' => 'payment', 'action' => 'post',
            'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'payment' => ['money_account_id' => $cash['id'], 'method' => 'cash'],
            'lines' => [['ledger_id' => $this->ledger('6105'), 'amount' => '1.00']]])->assertStatus(202);
        $r->assertJsonPath('data.approval.required_permission', 'banking.approve');
        $this->req('POST', '/api/v1/vouchers', $this->finance, ['entry_type' => 'journal', 'action' => 'post', 'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'lines' => [['ledger_id' => $this->ledger('6501'), 'debit' => '2500000.00'], ['ledger_id' => $this->ledger('1509'), 'credit' => '2500000.00']]])
            ->assertCreated()->assertJsonPath('data.status', 'posted');
    }

    public function test_notifications_read_and_isolation(): void
    {
        $this->submitted();
        $n = $this->req('GET', '/api/v1/notifications', $this->finance)->json('data.0');
        $this->req('POST', "/api/v1/notifications/{$n['id']}/read", $this->finance)->assertNoContent();
        $this->assertSame(0, $this->req('GET', '/api/v1/notifications?unread=true', $this->finance)->json('meta.total'));
        $this->assertProblem($this->req('POST', "/api/v1/notifications/{$n['id']}/read", $this->owner), 404, 'not_found');
        $this->req('POST', '/api/v1/notifications/read-all', $this->owner)->assertNoContent();
        $this->assertSame(0, $this->req('GET', '/api/v1/notifications?unread=true', $this->owner)->json('meta.total'));
        $this->assertProblem($this->req('GET', '/api/v1/notifications?colour=red', $this->owner), 422, 'validation_failed');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $id = DB::table('approval_requests')->value('id');
        $this->assertProblem($this->api('GET', "/api/v1/approvals/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/approvals/{$id}/approve", $ot, $other->id, [], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/approvals', $ot, $other->id)->json('meta.total'));
    }
}
