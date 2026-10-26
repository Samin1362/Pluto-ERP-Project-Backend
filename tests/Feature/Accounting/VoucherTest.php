<?php

namespace Tests\Feature\Accounting;

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

/** /vouchers (H8–H13; BR-001, BR-002, BR-004, BR-006, BR-007, BR-009, BR-025, BR-031, BR-053, BR-065; R-09, R-27; backlog P7.1-BE-04). */
class VoucherTest extends TestCase
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
        if ($method === 'POST' && ($uri === '/api/v1/vouchers' || str_ends_with($uri, '/post') || $uri === '/api/v1/money-accounts')) {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    private function balance(int $ledgerId): string
    {
        $this->useBooks();

        return (string) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b');
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function journal(string $action = 'post', string $amount = '5000.00', array $over = []): array
    {
        return $over + ['entry_type' => 'journal', 'action' => $action, 'date' => $this->today(), 'narration' => 'Depreciation for the month', 'lines' => [
            ['ledger_id' => $this->ledger('6501'), 'debit' => $amount, 'line_narration' => 'Vans'],
            ['ledger_id' => $this->ledger('1509'), 'credit' => $amount],
        ]];
    }

    private function cash(string $opening = '50000.00'): array
    {
        return $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash', 'opening_balance' => $opening])->assertCreated()->json('data');
    }

    private function bank(string $opening = '200000.00', ?string $overdraft = null): array
    {
        return $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012-4521', 'opening_balance' => $opening, 'overdraft_limit' => $overdraft])->assertCreated()->json('data');
    }

    public function test_a_journal_saved_as_draft_reserves_its_number_and_posts_later(): void
    {
        $r = $this->req('POST', '/api/v1/vouchers', $this->journal('draft'))->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('data.entry_type', 'journal')->assertJsonPath('data.total_debit', '5000.00')
            ->assertJsonPath('data.lines.0.ledger_code', '6501')->assertJsonPath('data.lines.0.line_narration', 'Vans')->assertJsonPath('data.lines.1.credit', '5000.00')
            ->assertJsonPath('data.amount_in_words', 'Taka five thousand only');
        $this->assertMatchesRegularExpression('/^JV-\d{4}-0001$/', $r->json('data.voucher_number'));
        $this->assertSame('0.00', $this->balance($this->ledger('6501')), 'a draft does not touch the books (BR-005)');
        $id = $r->json('data.id');

        $p = $this->req('POST', "/api/v1/vouchers/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.voucher_number', $r->json('data.voucher_number'));
        $this->assertSame('5000.00', $this->balance($this->ledger('6501')));
        $this->req('POST', "/api/v1/vouchers/{$id}/post")->assertOk()->assertJsonPath('data.version', $p->json('data.version')); // BR-009: once
        $this->assertSame('5000.00', $this->balance($this->ledger('6501')));
        $this->assertSame(['created', 'posted'], AuditLog::where('auditable_type', 'journal_entries')->where('auditable_id', $id)->orderBy('id')->pluck('action')->all());

        $this->req('POST', '/api/v1/vouchers', $this->journal())->assertCreated()->assertJsonPath('data.status', 'posted');
        $this->assertSame('10000.00', $this->balance($this->ledger('6501')));
    }

    public function test_t_c_ac_c_001_unbalanced_and_one_sided_lines_are_refused(): void
    {
        $bad = $this->journal('post', '5000.00');
        $bad['lines'][1]['credit'] = '4000.00';
        $c = $this->req('POST', '/api/v1/vouchers/calculate', $bad)->assertOk();
        $c->assertJsonPath('data.balanced', false)->assertJsonPath('data.difference', '1000.00')->assertJsonPath('data.total_credit', '4000.00');
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $bad), 422, 'unbalanced_entry');
        $this->req('POST', '/api/v1/vouchers', ['action' => 'draft'] + $bad)->assertCreated(); // a draft may be unfinished
        $this->assertSame(0, JournalEntry::where('status', 'posted')->count());

        $both = $this->journal();
        $both['lines'][0]['credit'] = '5000.00';
        $e = $this->assertProblem($this->req('POST', '/api/v1/vouchers', $both), 422, 'validation_failed')->json('errors');
        $this->assertSame('Enter a debit or a credit, not both (BR-002)', $e['lines.0.debit'][0]);
        $one = $this->journal();
        unset($one['lines'][1]);
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $one), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $this->journal('post', '5000.00', ['colour' => 'red'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $this->journal('post', '5000.00', ['entry_type' => 'sales_invoice'])), 422, 'validation_failed');
    }

    public function test_payment_receipt_and_contra_take_their_money_side_from_the_account(): void
    {
        $cash = $this->cash();
        $bank = $this->bank();
        $pay = $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'payment', 'action' => 'post', 'date' => $this->today(), 'narration' => 'October rent',
            'payment' => ['money_account_id' => $bank['id'], 'method' => 'cheque', 'cheque_number' => '448812', 'bank_charge' => '50.00', 'payee_text' => 'Landlord'],
            'lines' => [['ledger_id' => $this->ledger('6101'), 'amount' => '25000.00']]])->assertCreated();
        $pay->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total_debit', '25050.00')->assertJsonPath('data.payment.direction', 'out')
            ->assertJsonPath('data.payment.amount', '25000.00')->assertJsonPath('data.payment.bank_charge', '50.00')->assertJsonPath('data.payment.cheque_number', '448812');
        $this->assertMatchesRegularExpression('/^PV-/', $pay->json('data.voucher_number'));
        $this->assertSame([['6101', '25000.00', '0.00'], ['6702', '50.00', '0.00'], [$bank['ledger_code'], '0.00', '25050.00']],
            array_map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']], $pay->json('data.lines')));
        $this->assertSame('174950.00', $this->balance($bank['ledger_id']));

        $customer = $this->customer();
        $rec = $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'receipt', 'action' => 'post', 'date' => $this->today(),
            'payment' => ['money_account_id' => $cash['id'], 'method' => 'cash'], 'lines' => [['ledger_id' => $customer->ledger_id, 'amount' => '3000.00']]])->assertCreated();
        $rec->assertJsonPath('data.payment.direction', 'in')->assertJsonPath('data.payment.party.id', $customer->id)->assertJsonPath('data.payment.unapplied_amount', '3000.00');
        $this->assertMatchesRegularExpression('/^RV-/', $rec->json('data.voucher_number'));
        $this->assertSame('-3000.00', $this->balance($customer->ledger_id), 'an advance from the customer (BR-065)');

        $contra = $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'contra', 'action' => 'post', 'date' => $this->today(), 'narration' => 'Cash deposited',
            'payment' => ['money_account_id' => $cash['id'], 'counter_money_account_id' => $bank['id'], 'method' => 'cash'],
            'lines' => [['ledger_id' => $bank['ledger_id'], 'amount' => '40000.00']]])->assertCreated();
        $this->assertMatchesRegularExpression('/^CV-/', $contra->json('data.voucher_number'));
        $this->assertSame(['13000.00', '214950.00'], [$this->balance($cash['ledger_id']), $this->balance($bank['ledger_id'])]);
        $e = $this->assertProblem($this->req('POST', '/api/v1/vouchers', ['entry_type' => 'contra', 'action' => 'post', 'date' => $this->today(),
            'payment' => ['money_account_id' => $cash['id'], 'method' => 'cash'], 'lines' => [['ledger_id' => $this->ledger('6101'), 'amount' => '10.00']]]), 422, 'validation_failed')->json('errors');
        $this->assertSame('A contra moves money between cash and bank accounts only', $e['lines.0.ledger_id'][0]);
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', ['entry_type' => 'payment', 'action' => 'post', 'date' => $this->today(),
            'lines' => [['ledger_id' => $this->ledger('6101'), 'amount' => '10.00']]]), 422, 'validation_failed')->assertJsonPath('errors.payment.0', 'Choose the cash or bank account');
    }

    public function test_b_r_031_cash_cannot_go_below_zero_and_bank_only_down_to_its_overdraft(): void
    {
        $cash = $this->cash('1000.00');
        $body = ['entry_type' => 'payment', 'action' => 'post', 'date' => $this->today(), 'payment' => ['money_account_id' => $cash['id'], 'method' => 'cash'],
            'lines' => [['ledger_id' => $this->ledger('6105'), 'amount' => '1500.00']]];
        $this->req('POST', '/api/v1/vouchers/calculate', $body)->assertOk()->assertJsonPath('data.warnings.0.code', 'INSUFFICIENT_FUNDS')->assertJsonPath('data.balanced', true);
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $body), 422, 'insufficient_funds');
        $this->assertSame(0, JournalEntry::where('entry_type', 'payment')->count(), 'nothing saved');

        $bank = $this->bank('1000.00', '5000.00');
        $body['payment']['money_account_id'] = $bank['id'];
        $body['lines'][0]['amount'] = '6000.00';
        $this->req('POST', '/api/v1/vouchers', $body)->assertCreated();
        $this->assertSame('-5000.00', $this->balance($bank['ledger_id']));
        $body['lines'][0]['amount'] = '0.01';
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $body), 422, 'insufficient_funds');
    }

    public function test_t_c_ac_c_004_a_locked_period_is_refused_by_name(): void
    {
        $this->useBooks();
        $period = AccountingPeriod::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->firstOrFail();
        $draft = $this->req('POST', '/api/v1/vouchers', $this->journal('draft'))->json('data.id');
        $period->forceFill(['status' => 'locked'])->save();
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$draft}/post"), 422, 'period_locked')->assertJsonPath('period', $period->name);
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $this->journal()), 422, 'period_locked');
        $this->assertSame('0.00', $this->balance($this->ledger('6501')));
    }

    public function test_t_c_ac_c_018_a_back_dated_voucher_needs_confirmation(): void
    {
        $date = CarbonImmutable::now('Asia/Dhaka')->subDays(10)->toDateString();
        $this->useBooks();
        if (AccountingPeriod::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->doesntExist()) {
            $this->markTestSkipped('No period covers ten days ago in this test database');
        }
        $body = $this->journal('post', '5000.00', ['date' => $date]);
        $this->req('POST', '/api/v1/vouchers/calculate', $body)->assertOk()->assertJsonPath('data.warnings.0.code', 'BACKDATED');
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $body), 422, 'backdated_confirmation_required');
        $this->req('POST', '/api/v1/vouchers', $body + ['confirm_backdated' => true])->assertCreated()->assertJsonPath('data.status', 'posted');
    }

    public function test_t_c_ac_c_009_a_journal_above_the_accountant_limit_waits_for_approval(): void
    {
        $this->userWithRole('accountant');
        $accountant = $this->login('accountant@meghna.example');
        $c = $this->req('POST', '/api/v1/vouchers/calculate', $this->journal('post', '150000.00'), token: $accountant)->assertOk();
        $c->assertJsonPath('data.needs_approval', true)->assertJsonPath('data.approval_limit', '100000.00')->assertJsonPath('data.warnings.0.code', 'APPROVAL_REQUIRED');
        $r = $this->req('POST', '/api/v1/vouchers', $this->journal('post', '150000.00'), token: $accountant)->assertStatus(202);
        $r->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.approval.status', 'pending')->assertJsonPath('data.approval.amount', '150000.00')
            ->assertJsonPath('data.approval.limit', '100000.00')->assertJsonPath('data.approval.required_permission', 'accounting.approve');
        $this->assertSame('0.00', $this->balance($this->ledger('6501')), 'balances unchanged until approved');
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$r->json('data.id')}/post", token: $accountant), 409, 'invalid_transition');
        $this->req('POST', '/api/v1/vouchers', $this->journal('post', '100000.00'), token: $accountant)->assertCreated()->assertJsonPath('data.status', 'posted');
        $this->req('POST', '/api/v1/vouchers', $this->journal('post', '900000.00'))->assertCreated(); // the Owner has no limit
    }

    public function test_t_c_aut_h_021_the_permission_depends_on_the_voucher_type(): void
    {
        $this->useBooks();
        $role = Role::create(['name' => 'Cashier', 'slug' => 'cashier', 'is_system' => false, 'payment_approval_limit' => '100000.00', 'journal_approval_limit' => '0.00']);
        $role->permissions()->sync(Permission::whereIn('key', ['banking.create', 'banking.view'])->pluck('id')->all());
        $this->addMember($this->books, $this->makeUser('cashier@meghna.example'), 'cashier');
        $cashier = $this->login('cashier@meghna.example');
        $cash = $this->cash();
        $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'payment', 'action' => 'post', 'date' => $this->today(), 'payment' => ['money_account_id' => $cash['id'], 'method' => 'cash'],
            'lines' => [['ledger_id' => $this->ledger('6105'), 'amount' => '700.00']]], token: $cashier)->assertCreated();
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $this->journal(), token: $cashier), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', '/api/v1/vouchers/calculate', $this->journal(), token: $cashier), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', '/api/v1/vouchers', token: $cashier), 403, 'permission_denied');
    }

    public function test_drafts_are_replaced_submitted_and_deleted_posted_vouchers_are_not(): void
    {
        $id = $this->req('POST', '/api/v1/vouchers', $this->journal('draft'))->json('data.id');
        $url = "/api/v1/vouchers/{$id}";
        $this->assertProblem($this->req('PUT', $url, $this->journal('draft', '6000.00')), 428, 'if_match_required');
        $this->req('PUT', $url, $this->journal('draft', '6000.00'), ['If-Match' => 'W/"1"'])->assertOk()->assertHeader('ETag', 'W/"2"')
            ->assertJsonPath('data.total_debit', '6000.00')->assertJsonPath('data.status', 'draft');
        $this->assertProblem($this->req('PUT', $url, $this->journal('draft'), ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $this->assertProblem($this->req('PUT', $url, $this->journal('draft', '6000.00', ['entry_type' => 'payment']), ['If-Match' => 'W/"2"']), 422, 'validation_failed');

        $this->req('POST', "{$url}/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.approval.status', 'pending');
        $this->assertProblem($this->req('DELETE', $url), 409, 'not_draft');

        $draft = $this->req('POST', '/api/v1/vouchers', $this->journal('draft'))->json('data');
        $this->req('DELETE', "/api/v1/vouchers/{$draft['id']}")->assertNoContent();
        $this->assertNull(JournalEntry::find($draft['id']));
        $this->assertSame(['number_voided' => $draft['voucher_number']], AuditLog::where('action', 'deleted')->where('auditable_id', $draft['id'])->sole()->new_values);

        $posted = $this->req('POST', '/api/v1/vouchers', $this->journal())->json('data.id');
        $this->assertProblem($this->req('DELETE', "/api/v1/vouchers/{$posted}"), 409, 'not_draft');
        $this->assertProblem($this->req('PUT', "/api/v1/vouchers/{$posted}", $this->journal('draft'), ['If-Match' => 'W/"2"']), 409, 'not_draft');
    }

    public function test_list_day_book_templates_permissions_and_isolation(): void
    {
        $this->req('POST', '/api/v1/vouchers', $this->journal())->assertCreated();
        $this->req('POST', '/api/v1/vouchers', $this->journal('draft', '700.00', ['narration' => 'Accrued electricity']))->assertCreated();
        $all = $this->req('GET', '/api/v1/vouchers')->assertOk();
        $this->assertSame(2, $all->json('meta.total'));
        $this->assertSame([], $all->json('data.0.lines'), 'list rows are summaries');
        $this->assertSame(['Accrued electricity'], array_column($this->req('GET', '/api/v1/vouchers?status=draft')->json('data'), 'narration'));
        $this->assertSame(1, $this->req('GET', '/api/v1/vouchers?q=electricity')->json('meta.total'));
        $this->assertSame(2, $this->req('GET', '/api/v1/vouchers?ledger_id='.$this->ledger('6501').'&entry_type=journal')->json('meta.total'));
        $this->assertProblem($this->req('GET', '/api/v1/vouchers?sort=colour'), 422, 'validation_failed');

        $t = $this->req('GET', '/api/v1/voucher-templates')->assertOk()->json('data.templates');
        $dep = collect($t)->firstWhere('key', 'depreciation');
        $this->assertSame([['6501', 'debit'], ['1509', 'credit']], array_map(fn ($l) => [$l['ledger_code'], $l['side']], $dep['lines']));

        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/vouchers', token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/vouchers', $this->journal(), token: $viewer), 403, 'permission_denied');
        $id = JournalEntry::where('status', 'draft')->value('id');
        $this->assertProblem($this->req('DELETE', "/api/v1/vouchers/{$id}", token: $viewer), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/vouchers/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/vouchers/{$id}/post", $ot, $other->id, [], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/vouchers', $ot, $other->id)->json('meta.total'));
    }
}
