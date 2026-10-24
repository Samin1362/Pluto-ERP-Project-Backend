<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\AccountMapper;
use App\Models\Company;
use App\Models\Ledger;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AccountMapper (06 §3; backlog P4.1-BE-04) and the journal_lines CHECKs behind BR-002 (TC-ACC-002). */
class AccountMapperTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        app(CurrentCompany::class)->set($this->company, null);
    }

    public function test_mapping_keys_resolve_to_the_company_ledgers(): void
    {
        $m = app(AccountMapper::class);
        $this->assertSame('5001', $m->ledgerFor('cogs')->code);
        $this->assertSame('6799', $m->ledgerFor('rounding')->code);
        $this->assertSame('5101', $m->ledgerFor('purchases_non_stock')->code);
        $this->assertSame($this->company->id, $m->ledgerFor('cogs')->company_id);
        $this->assertSame(DB::table('account_groups')->where('company_id', $this->company->id)->where('code', '1301')->value('id'), $m->groupIdFor('inventory_group'));
    }

    public function test_missing_group_or_inactive_mappings_are_mapping_missing(): void
    {
        $m = app(AccountMapper::class);
        foreach (['no_such_key', 'inventory_group'] as $key) { // a group mapping is not a ledger
            try {
                $m->ledgerFor($key);
                $this->fail("{$key} resolved");
            } catch (ApiProblem $e) {
                $this->assertSame('mapping_missing', $e->errorCode);
                $this->assertSame($key, $e->extra['mapping_key']);
            }
        }
        Ledger::where('code', '6799')->update(['is_active' => false]);
        $this->expectException(ApiProblem::class);
        (new AccountMapper)->ledgerFor('rounding');
    }

    public function test_tc_acc_002_a_line_with_both_or_neither_side_is_refused_by_the_database(): void
    {
        $fy = DB::table('fiscal_years')->where('company_id', $this->company->id)->first();
        $period = DB::table('accounting_periods')->where('fiscal_year_id', $fy->id)->first();
        $entry = DB::table('journal_entries')->insertGetId(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'accounting_period_id' => $period->id,
            'entry_type' => 'journal', 'voucher_number' => 'JV-T-1', 'entry_date' => $period->start_date, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $ledger = DB::table('ledgers')->where('company_id', $this->company->id)->where('code', '5001')->value('id');
        $line = fn ($dr, $cr) => DB::table('journal_lines')->insert(['company_id' => $this->company->id, 'journal_entry_id' => $entry, 'line_no' => 1,
            'ledger_id' => $ledger, 'debit' => $dr, 'credit' => $cr, 'entry_date' => $period->start_date]);
        foreach ([['10.00', '10.00'], ['0.00', '0.00'], ['-5.00', '0.00']] as [$dr, $cr]) {
            try {
                DB::transaction(fn () => $line($dr, $cr));
                $this->fail("debit {$dr} / credit {$cr} accepted");
            } catch (QueryException $e) {
                $this->assertStringContainsString('journal_lines_rule', $e->getMessage());
            }
        }
        $line('10.00', '0.00');
        $this->assertSame(1, DB::table('journal_lines')->where('journal_entry_id', $entry)->count());
    }
}
