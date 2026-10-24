<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Parties\PartyService;
use App\Domain\Shared\Money;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PostingService, system journal numbers and the immutability triggers (06 §4, §15; BR-003, BR-004; TC-ACC-003; P4.1-BE-05). */
class PostingServiceTest extends TestCase
{
    private Company $company;

    private PostingService $posting;

    private AccountingPeriod $open;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        app(CurrentCompany::class)->set($this->company, null);
        $this->posting = app(PostingService::class);
        $this->open = AccountingPeriod::where('status', 'open')->orderBy('start_date')->firstOrFail();
    }

    private function ledger(string $code): Ledger
    {
        return Ledger::where('code', $code)->firstOrFail();
    }

    private function bill(string $amount = '626000.00'): JournalBuilder
    {
        return (new JournalBuilder)->debit($this->ledger('1301-01'), Money::of($amount), ['warehouse_id' => null])->credit($this->ledger('9999'), Money::of($amount));
    }

    private function date(): string
    {
        return $this->open->start_date->toDateString();
    }

    public function test_a_document_entry_is_posted_with_its_own_number_and_linked_to_the_source(): void
    {
        $source = app(PartyService::class)->create(['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333', 'default_currency_code' => 'BDT'])['party'];
        $e = $this->posting->persist($this->bill(), $source, 'purchase_bill', $this->date(), 'BILL-2026-0224', 'Supplier bill 4471', 'SB-4471');

        $e = JournalEntry::with('lines')->findOrFail($e->id);
        $this->assertSame(['posted', 'purchase_bill', 'BILL-2026-0224', '626000.00', '626000.00'],
            [$e->status, $e->entry_type, $e->voucher_number, (string) $e->total_debit, (string) $e->total_credit]);
        $this->assertSame([$this->open->id, $this->open->fiscal_year_id], [$e->accounting_period_id, $e->fiscal_year_id]);
        $this->assertFalse($e->is_system_generated);
        $this->assertNotNull($e->posted_at);
        $this->assertSame($source->id, $e->source->id, 'entry ↔ source link');
        $this->assertCount(2, $e->lines);
        foreach ($e->lines as $l) {
            $this->assertTrue($l->is_posted);
            $this->assertSame($this->date(), $l->entry_date->toDateString());
        }
    }

    public function test_system_entries_take_the_next_system_journal_number_of_the_year(): void
    {
        $fy = FiscalYear::findOrFail($this->open->fiscal_year_id);
        $prefix = 'SJ-'.$fy->start_date->year.'-';
        $a = $this->posting->persist($this->bill('10.00'), null, 'cancellation', $this->date());
        $b = $this->posting->persist($this->bill('20.00'), null, 'opening_balance', $this->date());
        $this->assertSame(["{$prefix}0001", "{$prefix}0002"], [$a->voucher_number, $b->voucher_number]);
        $this->assertTrue($a->is_system_generated);
    }

    public function test_an_unbalanced_entry_or_a_locked_period_writes_nothing_and_takes_no_number(): void
    {
        $seq = fn () => DB::table('number_sequences')->where('company_id', $this->company->id)->where('document_type', 'system_journal')->value('next_number');
        $before = $seq();
        $unbalanced = (new JournalBuilder)->debit($this->ledger('1301-01'), Money::of('10'))->credit($this->ledger('9999'), Money::of('9'));
        try {
            DB::transaction(fn () => $this->posting->persist($unbalanced, null, 'journal', $this->date()));
            $this->fail('unbalanced posted');
        } catch (ApiProblem $e) {
            $this->assertSame('unbalanced_entry', $e->errorCode);
        }
        $this->open->update(['status' => 'locked']);
        try {
            DB::transaction(fn () => $this->posting->persist($this->bill(), null, 'journal', $this->date()));
            $this->fail('locked period posted');
        } catch (ApiProblem $e) {
            $this->assertSame('period_locked', $e->errorCode);
        }
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, DB::table('journal_lines')->count());
        $this->assertSame($before, $seq());
    }

    public function test_tc_acc_003_posted_lines_and_entries_cannot_be_changed_in_sql(): void
    {
        $e = $this->posting->persist($this->bill(), null, 'journal', $this->date(), 'JV-2026-0001');
        $line = DB::table('journal_lines')->where('journal_entry_id', $e->id)->value('id');
        $refused = function (callable $sql, string $what) {
            try {
                $sql();
                $this->fail("{$what} was allowed");
            } catch (QueryException $ex) {
                $this->assertStringContainsString('BR-003', $ex->getMessage(), $what);
            }
        };
        $refused(fn () => DB::table('journal_lines')->where('id', $line)->update(['debit' => '1.00']), 'update a posted line');
        $refused(fn () => DB::table('journal_lines')->where('id', $line)->delete(), 'delete a posted line');
        $refused(fn () => DB::table('journal_entries')->where('id', $e->id)->update(['narration' => 'edited']), 'edit a posted entry');
        $refused(fn () => DB::table('journal_entries')->where('id', $e->id)->update(['status' => 'reversed', 'total_debit' => '1.00', 'total_credit' => '1.00']), 'reverse and change totals');
        $refused(fn () => DB::table('journal_entries')->where('id', $e->id)->delete(), 'delete a posted entry');
        $this->assertSame('626000.00', DB::table('journal_lines')->where('id', $line)->value('debit'), 'row unchanged');

        // The one allowed change: posted → reversed (with the version bump); after that the entry is final.
        DB::table('journal_entries')->where('id', $e->id)->update(['status' => 'reversed', 'lock_version' => 2, 'updated_at' => now()]);
        $this->assertSame('reversed', DB::table('journal_entries')->where('id', $e->id)->value('status'));
        $refused(fn () => DB::table('journal_entries')->where('id', $e->id)->update(['status' => 'posted']), 'un-reverse');
    }

    public function test_draft_lines_stay_editable(): void
    {
        $fy = $this->open->fiscal_year_id;
        $id = DB::table('journal_entries')->insertGetId(['company_id' => $this->company->id, 'fiscal_year_id' => $fy, 'accounting_period_id' => $this->open->id,
            'entry_type' => 'journal', 'voucher_number' => 'JV-D-1', 'entry_date' => $this->date(), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_lines')->insert(['company_id' => $this->company->id, 'journal_entry_id' => $id, 'line_no' => 1, 'ledger_id' => $this->ledger('5001')->id,
            'debit' => '5.00', 'credit' => '0.00', 'entry_date' => $this->date(), 'is_posted' => 0]);
        DB::table('journal_lines')->where('journal_entry_id', $id)->update(['debit' => '6.00']);
        DB::table('journal_entries')->where('id', $id)->update(['narration' => 'draft edit']);
        DB::table('journal_lines')->where('journal_entry_id', $id)->delete();
        DB::table('journal_entries')->where('id', $id)->delete();
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_posting_uses_mapped_ledgers(): void
    {
        $m = app(AccountMapper::class);
        $b = (new JournalBuilder)->debit($m->ledgerFor('cogs'), Money::of('845.00'))->credit($this->ledger('1301-01'), Money::of('845.00'));
        $e = $this->posting->persist($b, null, 'journal', $this->date(), 'JV-2026-0002');
        $this->assertSame(['5001', '1301-01'], $e->lines->map(fn ($l) => $l->ledger->code)->all());
    }
}
