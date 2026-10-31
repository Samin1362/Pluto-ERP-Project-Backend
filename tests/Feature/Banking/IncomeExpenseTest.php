<?php

namespace Tests\Feature\Banking;

use App\Models\Attachment;
use App\Models\JournalEntry;
use App\Models\RecurringTemplate;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Income and expenses: /ie-categories, /ie-entries, /recurring-templates, /income-expenses/overview, /reports/income,
 * /reports/expenses (I1–I10; BR-054, BR-048, BR-007; GAP-16; TC-BNK-007, TC-BNK-008; backlog P13.1-BE-02..05).
 */
class IncomeExpenseTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private array $bank;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->today = now('Asia/Dhaka')->toDateString();
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '200000.00'])->assertCreated()->json('data');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    private function category(string $name, string $kind = 'expense', array $extra = []): array
    {
        return $this->req('POST', '/api/v1/ie-categories', ['kind' => $kind, 'name' => $name] + $extra)->assertCreated()->json('data');
    }

    private function expense(array $cat, string $amount, array $extra = [], ?string $token = null)
    {
        return $this->req('POST', '/api/v1/ie-entries', ['kind' => $cat['kind'], 'amount' => $amount, 'date' => $this->today, 'category_id' => $cat['id'],
            'money_account_id' => $this->bank['id'], 'method' => 'bank_transfer'] + $extra, $token);
    }

    private function lines(int $entryId): array
    {
        return DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $entryId)->orderBy('l.line_no')
            ->get(['g.code', 'l.debit', 'l.credit', 'l.is_posted'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
    }

    private function bankCode(): string
    {
        return (string) DB::table('ledgers')->where('id', $this->bank['ledger_id'])->value('code');
    }

    private function bankBalance(): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $this->bank['ledger_id'])->where('is_posted', 1)->selectRaw('SUM(debit) - SUM(credit) b')->value('b');
    }

    public function test_categories_take_a_new_or_an_existing_ledger_and_report_budget_use(): void
    {
        $office = $this->category('Office supplies', 'expense', ['create_ledger' => true, 'monthly_budget' => '10000.00', 'warn_at_percent' => '90']);
        $this->assertMatchesRegularExpression('/^61\d\d$/', $office['ledger_code']);
        $this->assertSame(['10000.00', '0.00', '0.00', 'not_used'], [$office['monthly_budget'], $office['spent'], $office['used_pct'], $office['status']]);
        $rent = $this->category('Rent', 'expense', ['ledger_id' => $this->ledger('6101')]);
        $this->assertSame('6101', $rent['ledger_code']);

        $this->assertProblem($this->req('POST', '/api/v1/ie-categories', ['kind' => 'expense', 'name' => 'Rent again', 'ledger_id' => $this->ledger('6101')]), 422, 'validation_failed')
            ->assertJsonPath('errors.ledger_id.0', 'Rent already belongs to another category');
        $this->assertProblem($this->req('POST', '/api/v1/ie-categories', ['kind' => 'expense', 'name' => 'Interest', 'ledger_id' => $this->ledger('4302')]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/ie-categories', ['kind' => 'expense', 'name' => 'Rent', 'create_ledger' => true]), 422, 'validation_failed');

        $this->expense($office, '9500.00')->assertCreated();
        $row = collect($this->req('GET', '/api/v1/ie-categories?kind=expense')->assertOk()->json('data'))->firstWhere('id', $office['id']);
        $this->assertSame(['9500.00', '95.00', 'near_limit'], [$row['spent'], $row['used_pct'], $row['status']]);
        $this->expense($office, '1000.00')->assertCreated();
        $row = collect($this->req('GET', '/api/v1/ie-categories?kind=expense&period=this_month')->json('data'))->firstWhere('id', $office['id']);
        $this->assertSame(['10500.00', '105.00', 'over_budget'], [$row['spent'], $row['used_pct'], $row['status']]);
        $this->assertSame('not_used', collect($this->req('GET', '/api/v1/ie-categories')->json('data'))->firstWhere('id', $rent['id'])['status']);

        $this->req('PATCH', "/api/v1/ie-categories/{$office['id']}", ['kind' => 'expense', 'name' => 'Office and stationery', 'monthly_budget' => '20000.00'])->assertOk()
            ->assertJsonPath('data.name', 'Office and stationery')->assertJsonPath('data.status', 'on_track')->assertJsonPath('data.used_pct', '52.50');
        $this->assertProblem($this->req('PATCH', "/api/v1/ie-categories/{$office['id']}", ['kind' => 'income', 'name' => 'X']), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/ie-categories?colour=red'), 422, 'validation_failed');
    }

    public function test_t_c_bn_k_007_an_entry_needs_a_category_and_an_amount_and_posts_the_category_against_the_account(): void
    {
        $fuel = $this->category('Fuel', 'expense', ['create_ledger' => true]);
        $this->assertProblem($this->req('POST', '/api/v1/ie-entries', ['kind' => 'expense', 'amount' => '2500.00', 'date' => $this->today,
            'money_account_id' => $this->bank['id'], 'method' => 'cash']), 422, 'validation_failed')->assertJsonPath('errors.category_id.0', 'Choose a category');
        $this->assertProblem($this->expense($fuel, '0.00'), 422, 'validation_failed')->assertJsonPath('errors.amount.0', 'Enter an amount greater than zero');

        $upload = $this->call('POST', '/api/v1/attachments', ['attachable_type' => 'voucher'], [], ['file' => UploadedFile::fake()->createWithContent('pump.pdf', "%PDF-1.4\n%%EOF")],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}", 'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json',
                'Idempotency-Key' => (string) Str::uuid()]))->assertCreated()->json('data.id');
        $this->useBooks();
        $r = $this->expense($fuel, '2500.00', ['payee_text' => 'Meghna Filling Station', 'reference' => 'RCPT-88', 'attachment_ids' => [$upload]])->assertCreated();
        $r->assertJsonPath('data.entry_type', 'expense')->assertJsonPath('data.status', 'posted');
        $this->assertMatchesRegularExpression('/^EXP-/', $r->json('data.voucher_number'));
        $this->assertSame([[$fuel['ledger_code'], '2500.00', '0.00'], [$this->bankCode(), '0.00', '2500.00']], $this->lines($r->json('data.id')));
        $this->assertSame('197500.00', $this->bankBalance());
        $this->assertSame($r->json('data.id'), Attachment::findOrFail($upload)->attachable_id);
        $this->assertDatabaseHas('payment_details', ['journal_entry_id' => $r->json('data.id'), 'direction' => 'out', 'payee_text' => 'Meghna Filling Station']);

        $list = $this->req('GET', '/api/v1/ie-entries?kind=expense')->assertOk();
        $list->assertJsonPath('data.0.voucher_number', $r->json('data.voucher_number'))->assertJsonPath('data.0.category', 'Fuel')->assertJsonPath('data.0.amount', '2500.00')
            ->assertJsonPath('data.0.account', $this->bank['name'])->assertJsonPath('data.0.method', 'bank_transfer')->assertJsonPath('data.0.has_receipt', true)
            ->assertJsonPath('data.0.description', 'Meghna Filling Station')->assertJsonPath('meta.total_amount', '2500.00');
        // The same pending file cannot be linked twice
        $this->assertProblem($this->expense($fuel, '100.00', ['attachment_ids' => [$upload]]), 422, 'validation_failed');
        $this->assertBooksAreConsistent();
    }

    public function test_income_with_a_tax_rule_splits_the_tax_and_an_accountant_above_the_limit_waits_for_approval(): void
    {
        $commission = $this->category('Commission', 'income', ['ledger_id' => $this->ledger('4304')]);
        $vat = TaxRule::create(['name' => 'VAT', 'rate' => '15.0000', 'applies_to' => 'sales', 'ledger_id' => $this->ledger('2205'), 'is_recoverable' => true]);
        $r = $this->expense($commission, '1150.00', ['tax_rule_id' => $vat->id])->assertCreated();
        $this->assertMatchesRegularExpression('/^INC-/', $r->json('data.voucher_number'));
        $this->assertSame([[$this->bankCode(), '1150.00', '0.00'], ['4304', '0.00', '1000.00'], ['2205', '0.00', '150.00']], $this->lines($r->json('data.id')));
        $this->assertDatabaseHas('payment_details', ['journal_entry_id' => $r->json('data.id'), 'direction' => 'in']);
        $rent = $this->category('Rent', 'expense', ['ledger_id' => $this->ledger('6101')]);
        $this->assertProblem($this->expense($rent, '100.00', ['tax_rule_id' => $vat->id]), 422, 'validation_failed'); // a sales rule on an expense

        $this->userWithRole('accountant', 'accounts@meghna.example');
        $accountant = $this->login('accounts@meghna.example');
        $big = $this->expense($rent, '60000.00', [], $accountant)->assertStatus(202);
        $big->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('approval_requests', ['required_permission' => 'banking.approve', 'status' => 'pending']);
        $this->expense($rent, '40000.00', [], $accountant)->assertCreated()->assertJsonPath('data.status', 'posted');
        $this->assertBooksAreConsistent();
    }

    public function test_ga_p_16_the_lists_show_category_lines_from_any_voucher(): void
    {
        $rent = $this->category('Rent', 'expense', ['ledger_id' => $this->ledger('6101')]);
        $this->expense($rent, '30000.00', ['payee_text' => 'Landlord'])->assertCreated();
        $jv = $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'journal', 'action' => 'post', 'date' => $this->today, 'narration' => 'Rent accrued for the depot',
            'lines' => [['ledger_id' => $this->ledger('6101'), 'debit' => '5000.00'], ['ledger_id' => $this->ledger('2202'), 'credit' => '5000.00']]])->assertCreated();
        $list = $this->req('GET', '/api/v1/ie-entries?kind=expense&category_id='.$rent['id'].'&sort=amount')->assertOk();
        $this->assertSame(['5000.00', '30000.00'], array_column($list->json('data'), 'amount'));
        $list->assertJsonPath('data.0.voucher_number', $jv->json('data.voucher_number'))->assertJsonPath('data.0.account', '—')->assertJsonPath('data.0.description', 'Rent accrued for the depot')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.total_amount', '35000.00');
        $this->req('GET', '/api/v1/ie-entries?kind=expense&missing_receipt=true&q=landlord')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '30000.00');
        $this->req('GET', '/api/v1/ie-entries?kind=income')->assertOk()->assertJsonCount(0, 'data');
        $this->assertProblem($this->req('GET', '/api/v1/ie-entries?colour=red'), 422, 'validation_failed');
    }

    public function test_t_c_bn_k_008_a_recurring_expense_makes_drafts_only_and_the_template_can_be_paused_and_ended(): void
    {
        $rent = $this->category('Rent', 'expense', ['ledger_id' => $this->ledger('6101')]);
        $next = now('Asia/Dhaka')->addMonthNoOverflow()->toDateString();
        $this->assertProblem($this->expense($rent, '30000.00', ['recurrence' => ['frequency' => 'monthly', 'next_run_on' => $this->today, 'ends_type' => 'never']]), 422, 'validation_failed');
        $this->expense($rent, '30000.00', ['payee_text' => 'Landlord', 'recurrence' => ['frequency' => 'monthly', 'next_run_on' => $next, 'ends_type' => 'after_count', 'ends_after_count' => 2]])
            ->assertCreated();
        $t = RecurringTemplate::firstOrFail();
        $this->assertSame([1, true, $next], [$t->occurrences_done, $t->is_active, $t->next_run_on->toDateString()]);
        $this->req('GET', '/api/v1/recurring-templates')->assertOk()->assertJsonPath('data.0.description', 'Rent — Landlord')->assertJsonPath('data.0.ends_after_count', 2)
            ->assertJsonPath('data.0.payload.amount', '30000.00');

        $this->artisan('pluto:recurring:run', ['--date' => now('Asia/Dhaka')->subDay()->toDateString()])->assertSuccessful();
        $this->useBooks();
        $this->assertSame(1, JournalEntry::where('entry_type', 'expense')->count(), 'nothing due before next_run_on');
        $balance = $this->bankBalance();
        $this->travelTo(CarbonImmutable::parse($next.' 06:00', 'Asia/Dhaka')); // its month has opened
        $this->artisan('pluto:recurring:run')->assertSuccessful();
        $this->useBooks();
        $draft = JournalEntry::where('entry_type', 'expense')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['draft', $next], [$draft->status, $draft->entry_date->toDateString()]);
        $this->assertSame($balance, $this->bankBalance(), 'BR-048: nothing posts automatically');
        $t->refresh();
        $this->assertSame([2, false, $draft->id], [$t->occurrences_done, $t->is_active, $t->last_entry_id], 'ended after 2');

        // Pause / resume / reschedule with If-Match (BR-049)
        $this->assertProblem($this->req('PATCH', "/api/v1/recurring-templates/{$t->id}", ['is_active' => true]), 428, 'if_match_required');
        $this->assertProblem($this->req('PATCH', "/api/v1/recurring-templates/{$t->id}", ['is_active' => true], null, ['If-Match' => 'W/"99"']), 409, 'version_conflict');
        $this->req('PATCH', "/api/v1/recurring-templates/{$t->id}", ['is_active' => true, 'ends_type' => 'never', 'frequency' => 'quarterly'], null, ['If-Match' => 'W/"'.$t->lock_version.'"'])
            ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.frequency', 'quarterly')->assertJsonPath('data.version', $t->lock_version + 1);
        $this->assertProblem($this->req('PATCH', "/api/v1/recurring-templates/{$t->id}", ['ends_type' => 'on_date'], null, ['If-Match' => 'W/"'.($t->lock_version + 1).'"']), 422, 'validation_failed');
        // The draft posts through the voucher like any other
        $this->req('POST', "/api/v1/vouchers/{$draft->id}/post", ['confirm_backdated' => true])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertBooksAreConsistent();
    }

    public function test_overview_and_reports_and_who_may_see_them(): void
    {
        $rent = $this->category('Rent', 'expense', ['ledger_id' => $this->ledger('6101'), 'monthly_budget' => '40000.00']);
        $fuel = $this->category('Fuel', 'expense', ['create_ledger' => true]);
        $this->expense($rent, '30000.00')->assertCreated();
        $this->expense($fuel, '10000.00')->assertCreated();

        $o = $this->req('GET', '/api/v1/income-expenses/overview?kind=expense&period=this_month')->assertOk();
        $o->assertJsonPath('data.totals.value', '40000.00')->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.totals.budget', '40000.00')
            ->assertJsonPath('data.totals.used_pct', '100.00')->assertJsonPath('data.totals.missing_receipts', 2)
            ->assertJsonPath('data.rows.0.name', 'Rent')->assertJsonPath('data.rows.0.share', '75.00')->assertJsonPath('data.rows.1.share', '25.00');
        $this->assertCount(12, $o->json('data.meta.months'));
        $this->assertSame('40000.00', collect($o->json('data.meta.months'))->last()['value']);
        $this->assertCount(2, $o->json('data.meta.recent'));

        $r = $this->req('GET', '/api/v1/reports/expenses')->assertOk();
        $key = 'm_'.now('Asia/Dhaka')->format('Y_m');
        $r->assertJsonPath('data.title', 'Expense report')->assertJsonPath('data.rows.0.name', 'Rent')->assertJsonPath("data.rows.0.{$key}", '30000.00')
            ->assertJsonPath('data.rows.0.total', '30000.00')->assertJsonPath('data.totals.total', '40000.00')->assertJsonPath("data.totals.{$key}", '40000.00');
        $this->assertContains($key, array_column($r->json('data.columns'), 'key'));
        $this->req('GET', '/api/v1/reports/expenses?group_by=quarter')->assertOk()->assertJsonPath('data.meta.group_by', 'quarter');
        $this->req('GET', '/api/v1/reports/income')->assertOk()->assertJsonPath('data.totals.total', '0.00');
        $csv = $this->req('GET', '/api/v1/reports/expenses?format=csv')->assertOk();
        $this->assertStringContainsString('Rent', $csv->streamedContent() ?: $csv->getContent());

        $this->userWithRole('viewer', 'viewer@meghna.example');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/income-expenses/overview?kind=expense', [], $viewer)->assertOk();
        $this->assertProblem($this->req('GET', '/api/v1/reports/expenses', [], $viewer), 403, 'permission_denied'); // DEC-13
        $this->assertProblem($this->req('POST', '/api/v1/ie-entries', [], $viewer), 403, 'permission_denied');
    }
}
