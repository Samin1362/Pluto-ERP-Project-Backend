<?php

namespace Tests\Feature\Banking;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\MoneyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** /money-accounts (G3/G4; BR-028, BR-031, R-13; docs 12 §3 picker masking; backlog P6.1-BE-01). */
class MoneyAccountTest extends TestCase
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
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function sonali(array $over = []): array
    {
        return $over + ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'branch_name' => 'Tejgaon', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012-3400-4521', 'routing_number' => '200263457', 'opening_balance' => '500000.00'];
    }

    private function fyStart(): string
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();

        return FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->value('start_date')->toDateString();
    }

    public function test_a_bank_account_gets_its_ledger_and_a_balanced_opening_entry(): void
    {
        $r = $this->req('POST', '/api/v1/money-accounts', $this->sonali())->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.account_type', 'bank')->assertJsonPath('data.name', 'Sonali Bank ••4521')->assertJsonPath('data.account_number_masked', '••4521')
            ->assertJsonPath('data.ledger_code', '1102-01')->assertJsonPath('data.book_balance', '500000.00')->assertJsonPath('data.currency_code', 'BDT')
            ->assertJsonPath('data.use_for_receipts', true)->assertJsonPath('data.use_for_payments', true)->assertJsonPath('data.show_on_invoices', false)
            ->assertJsonPath('data.unmatched_lines', 0)->assertJsonPath('data.is_active', true)->assertJsonPath('data.version', 1);
        $this->assertArrayNotHasKey('account_number', $r->json('data'), 'the full number is never sent');

        $account = MoneyAccount::findOrFail($r->json('data.id'));
        $ledger = Ledger::findOrFail($account->ledger_id);
        $this->assertSame(['Sonali Bank ••4521', 'bank', '1100'], [$ledger->name, $ledger->ledger_kind, DB::table('account_groups')->where('id', $ledger->account_group_id)->value('code')]);
        $entry = JournalEntry::with('lines')->where('source_type', 'money_accounts')->where('source_id', $account->id)->sole();
        $this->assertSame(['opening_balance', $this->fyStart(), true], [$entry->entry_type, $entry->entry_date->toDateString(), $entry->is_system_generated]);
        $this->assertSame([[$account->ledger_id, '500000.00', '0.00'], [Ledger::where('code', '3301')->value('id'), '0.00', '500000.00']],
            $entry->lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->all());
        $audit = AuditLog::where('action', 'created')->where('auditable_type', 'money_accounts')->where('auditable_id', $account->id)->sole();
        $this->assertSame('••4521', $audit->new_values['account_number'], 'the audit log keeps only the masked number');
    }

    public function test_cash_takes_over_cash_in_hand_and_cash_credit_sits_under_liabilities(): void
    {
        $cash = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash', 'opening_balance' => '25000.00'])->assertCreated();
        $cash->assertJsonPath('data.name', 'Cash in hand')->assertJsonPath('data.ledger_code', '1101-01')->assertJsonPath('data.book_balance', '25000.00');
        $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash', 'name' => 'Petty cash, Tejgaon'])->assertCreated()
            ->assertJsonPath('data.ledger_code', '1101-02')->assertJsonPath('data.book_balance', '0.00');
        $this->assertSame(1, JournalEntry::where('entry_type', 'opening_balance')->count(), 'no entry for a zero opening');

        $cc = $this->req('POST', '/api/v1/money-accounts', $this->sonali(['account_type' => 'cash_credit', 'name' => 'Sonali Bank cash credit',
            'overdraft_limit' => '2000000.00', 'opening_balance' => '-750000.00']))->assertCreated();
        $cc->assertJsonPath('data.ledger_code', '2301-01')->assertJsonPath('data.book_balance', '-750000.00')->assertJsonPath('data.overdraft_limit', '2000000.00');
        $group = DB::table('ledgers')->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
            ->where('ledgers.id', $cc->json('data.ledger_id'))->value('account_groups.nature');
        $this->assertSame('liability', $group, 'R-13: the cash-credit ledger is a current liability');
        $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'wallet', 'name' => 'bKash merchant 01711000111'])->assertCreated()->assertJsonPath('data.ledger_code', '1103-01');
    }

    public function test_validation(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank']), 422, 'validation_failed');
        foreach (['bank_name', 'account_name', 'account_number'] as $f) {
            $this->assertNotNull($r->json("errors.{$f}"), $f);
        }
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', []), 422, 'validation_failed')->assertJsonPath('errors.account_type.0', 'Choose the kind of account');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', ['account_type' => 'locker']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(['routing_number' => '12345'])), 422, 'validation_failed')
            ->assertJsonPath('errors.routing_number.0', 'The routing number has 9 digits');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(['opening_balance' => '1.234'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(['overdraft_limit' => '-1'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(['is_active' => false])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(['custodian_user_id' => $this->makeUser('stranger@x.example')->id])), 422, 'validation_failed');
        $this->assertSame(0, MoneyAccount::count());
    }

    public function test_the_list_is_a_picker_for_everyone_and_masked_without_banking_view(): void
    {
        $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash'])->assertCreated();
        $bank = $this->req('POST', '/api/v1/money-accounts', $this->sonali())->json('data');
        $off = $this->req('POST', '/api/v1/money-accounts', $this->sonali(['account_number' => '9999', 'name' => 'Old DBBL', 'opening_balance' => '0']))->json('data');
        $this->req('PATCH', "/api/v1/money-accounts/{$off['id']}", ['is_active' => false], ['If-Match' => 'W/"1"'])->assertOk();
        $this->req('PATCH', "/api/v1/money-accounts/{$bank['id']}", ['use_for_receipts' => false], ['If-Match' => 'W/"1"'])->assertOk();

        $names = fn ($r) => array_column($r->json('data'), 'name');
        $this->assertSame(['Cash in hand', 'Sonali Bank ••4521', 'Old DBBL'], $names($this->req('GET', '/api/v1/money-accounts')->assertOk()), 'active first, cash before bank');
        $this->assertSame(['Cash in hand'], $names($this->req('GET', '/api/v1/money-accounts?purpose=receipts')));
        $this->assertSame(['Cash in hand', 'Sonali Bank ••4521'], $names($this->req('GET', '/api/v1/money-accounts?purpose=payments')));
        $this->assertSame(['Sonali Bank ••4521', 'Old DBBL'], $names($this->req('GET', '/api/v1/money-accounts?type=bank')));
        $this->assertProblem($this->req('GET', '/api/v1/money-accounts?colour=red'), 422, 'validation_failed');

        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $row = $this->req('GET', '/api/v1/money-accounts?purpose=payments', token: $sales)->assertOk()->json('data.1');
        $this->assertSame(['id', 'account_type', 'name', 'use_for_receipts', 'use_for_payments', 'is_active'], array_keys($row), 'no balance, number or reconciliation');
        $this->assertProblem($this->req('GET', "/api/v1/money-accounts/{$bank['id']}", token: $sales), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/money-accounts/{$bank['id']}", token: $viewer)->assertOk()->assertJsonPath('data.book_balance', '500000.00');
        $this->assertProblem($this->req('POST', '/api/v1/money-accounts', $this->sonali(), token: $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('PATCH', "/api/v1/money-accounts/{$bank['id']}", ['name' => 'X'], ['If-Match' => 'W/"2"'], $viewer), 403, 'permission_denied');
    }

    public function test_edit_with_if_match_type_fixed_and_the_ledger_renamed(): void
    {
        $a = $this->req('POST', '/api/v1/money-accounts', $this->sonali())->json('data');
        $url = "/api/v1/money-accounts/{$a['id']}";
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Sonali current']), 428, 'if_match_required');
        $this->req('PATCH', $url, ['name' => 'Sonali current', 'show_on_invoices' => true, 'overdraft_limit' => '100000.00'], ['If-Match' => 'W/"1"'])->assertOk()
            ->assertHeader('ETag', 'W/"2"')->assertJsonPath('data.name', 'Sonali current')->assertJsonPath('data.show_on_invoices', true)
            ->assertJsonPath('data.overdraft_limit', '100000.00')->assertJsonPath('data.version', 2);
        $this->assertSame('Sonali current', Ledger::whereKey($a['ledger_id'])->value('name'));
        $this->assertProblem($this->req('PATCH', $url, ['name' => 'Y'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', $url, ['account_type' => 'cash'], ['If-Match' => 'W/"2"']), 422, 'validation_failed')
            ->assertJsonPath('errors.account_type.0', 'The account type cannot change; add a new account instead');
        $this->req('PATCH', $url, ['is_active' => false], ['If-Match' => 'W/"2"'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse((bool) Ledger::whereKey($a['ledger_id'])->value('is_active'));
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'money_accounts')->where('auditable_id', $a['id'])->exists());
    }

    public function test_b_r_028_the_opening_balance_changes_until_the_account_has_entries(): void
    {
        $a = $this->req('POST', '/api/v1/money-accounts', $this->sonali())->json('data');
        $url = "/api/v1/money-accounts/{$a['id']}";
        $this->req('PATCH', $url, ['opening_balance' => '450000.00'], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.book_balance', '450000.00');
        $this->assertSame(['reversed', 'posted'], JournalEntry::where('entry_type', 'opening_balance')->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, JournalEntry::where('entry_type', 'reversal')->count(), 'the old opening is reversed, not edited');

        // Any other entry on the ledger locks the opening balance.
        $this->useBooks();
        app(PostingService::class)->persist((new JournalBuilder)->debit($a['ledger_id'], Money::of('1000'))->credit(Ledger::where('code', '4302')->value('id'), Money::of('1000')),
            null, 'journal', CarbonImmutable::now('Asia/Dhaka'), null, 'Bank interest');
        $this->assertProblem($this->req('PATCH', $url, ['opening_balance' => '0'], ['If-Match' => 'W/"2"']), 422, 'validation_failed')
            ->assertJsonPath('errors.opening_balance.0', 'The opening balance is locked: this account already has entries (BR-028)');
        $this->req('PATCH', $url, ['opening_balance' => '450000.00', 'name' => 'Same opening'], ['If-Match' => 'W/"2"'])->assertOk(); // unchanged amount is fine
        $this->req('GET', $url)->assertJsonPath('data.book_balance', '451000.00');
    }

    public function test_isolation(): void
    {
        $a = $this->req('POST', '/api/v1/money-accounts', $this->sonali())->json('data');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/money-accounts/{$a['id']}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', "/api/v1/money-accounts/{$a['id']}", $ot, $other->id, ['name' => 'X'], ['If-Match' => 'W/"1"']), 404, 'not_found');
        $this->assertSame([], $this->api('GET', '/api/v1/money-accounts', $ot, $other->id)->json('data'));
        $this->useBooks();
    }
}
