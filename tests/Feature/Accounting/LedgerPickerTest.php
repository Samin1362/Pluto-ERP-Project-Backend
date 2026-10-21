<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P1.1-BE-03: GET /ledgers — chart with balances for accounting.view, picker by purpose without balances (R-26). */
class LedgerPickerTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        foreach (['accountant', 'sales', 'store'] as $role) {
            $this->addMember($this->company, $this->makeUser("{$role}@meghna.example"), $role);
        }
    }

    private function ledgerId(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    /** A posted entry: Dr Rent 12,500 / Cr Share capital 12,500. */
    private function postRent(): void
    {
        $cid = $this->company->id;
        $period = DB::table('accounting_periods')->where('company_id', $cid)->where('status', 'open')->orderBy('start_date')->first();
        $je = DB::table('journal_entries')->insertGetId(['company_id' => $cid, 'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
            'entry_type' => 'journal', 'voucher_number' => 'JV-TEST-0001', 'entry_date' => $period->start_date, 'status' => 'posted',
            'total_debit' => '12500.00', 'total_credit' => '12500.00', 'is_system_generated' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[1, $this->ledgerId('6101'), '12500.00', '0.00'], [2, $this->ledgerId('3101'), '0.00', '12500.00']] as [$no, $ledger, $dr, $cr]) {
            DB::table('journal_lines')->insert(['company_id' => $cid, 'journal_entry_id' => $je, 'line_no' => $no, 'ledger_id' => $ledger,
                'debit' => $dr, 'credit' => $cr, 'entry_date' => $period->start_date, 'is_posted' => true]);
        }
    }

    public function test_accountant_gets_the_chart_with_balances_and_group_paths(): void
    {
        $this->postRent();
        $t = $this->login('accountant@meghna.example');
        $r = $this->api('GET', '/api/v1/ledgers?per_page=100', $t, $this->company->id)->assertOk();
        $rows = collect($r->json('data'))->keyBy('code');

        $this->assertSame('12500.00', $rows['6101']['balance']);
        $this->assertSame('dr', $rows['6101']['balance_side']);
        $this->assertSame('12500.00', $rows['3101']['balance']);
        $this->assertSame('cr', $rows['3101']['balance_side']);
        $this->assertTrue($rows['6101']['has_entries']);
        $this->assertSame('expense', $rows['6101']['nature']);
        $this->assertSame('0.00', $rows['4101']['opening_balance']);
        $this->assertNotEmpty($rows['6101']['group_path']);
        $this->assertSame(['code', 'name'], array_slice(array_keys($rows['6101']), 1, 2));
    }

    public function test_sub_ledgers_appear_only_when_kind_asks_for_them(): void
    {
        $owner = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $party = $this->api('POST', '/api/v1/parties', $owner, $c, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'])
            ->assertCreated()->json('data.ledger_code');

        $all = array_column($this->api('GET', '/api/v1/ledgers?per_page=100', $owner, $c)->json('data'), 'code');
        $this->assertNotContains($party, $all);
        $this->assertContains($party, array_column($this->api('GET', '/api/v1/ledgers?kind=party', $owner, $c)->json('data'), 'code'));
        $this->assertSame(['tax'], array_values(array_unique(array_column($this->api('GET', '/api/v1/ledgers?kind=tax', $owner, $c)->json('data'), 'ledger_kind'))));
        $this->assertSame(['income'], array_values(array_unique(array_column($this->api('GET', '/api/v1/ledgers?nature=income', $owner, $c)->json('data'), 'nature'))));
        $this->api('GET', '/api/v1/ledgers?q=rent', $owner, $c)->assertJsonPath('data.0.code', '6101');
    }

    public function test_sales_staff_needs_a_purpose_and_never_sees_balances(): void
    {
        $this->postRent();
        $t = $this->login('sales@meghna.example');
        $c = $this->company->id;

        $this->assertProblem($this->api('GET', '/api/v1/ledgers', $t, $c), 422, 'purpose_required');
        $this->assertProblem($this->api('GET', '/api/v1/ledgers?purpose=any', $t, $c), 403, 'permission_denied');
        $this->assertProblem($this->api('GET', '/api/v1/ledgers?purpose=income_for_product', $t, $c), 403, 'permission_denied');

        $r = $this->api('GET', '/api/v1/ledgers?purpose=purchase_for_supplier&per_page=100', $t, $c)->assertOk();
        $this->assertNotEmpty($r->json('data'));
        foreach ($r->json('data') as $row) {
            $this->assertSame('expense', $row['nature']);
            $this->assertSame('general', $row['ledger_kind']);
            $this->assertTrue($row['is_active']);
            foreach (['balance', 'balance_side', 'opening_balance', 'opening_side'] as $k) {
                $this->assertArrayNotHasKey($k, $row);
            }
        }
    }

    public function test_store_keeper_can_pick_product_income_and_tax_ledgers(): void
    {
        $t = $this->login('store@meghna.example');
        $c = $this->company->id;
        $income = $this->api('GET', '/api/v1/ledgers?purpose=income_for_product', $t, $c)->assertOk();
        $this->assertSame(['income'], array_values(array_unique(array_column($income->json('data'), 'nature'))));
        $tax = $this->api('GET', '/api/v1/ledgers?purpose=tax', $t, $c)->assertOk();
        $this->assertSame(['tax'], array_values(array_unique(array_column($tax->json('data'), 'ledger_kind'))));
    }

    public function test_other_companies_ledgers_never_appear_and_bad_parameters_are_rejected(): void
    {
        $other = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('owner@padma.example'));
        $theirs = DB::table('ledgers')->where('company_id', $other->id)->pluck('id')->all();
        $owner = $this->login('owner@meghna.example');
        $c = $this->company->id;

        $ids = array_column($this->api('GET', '/api/v1/ledgers?per_page=100&active=all', $owner, $c)->json('data'), 'id');
        $this->assertNotEmpty($ids);
        $this->assertSame([], array_values(array_intersect($ids, $theirs)));
        $this->assertProblem($this->api('GET', '/api/v1/ledgers?balance=1', $owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/ledgers?purpose=everything', $owner, $c), 422, 'validation_failed');
    }
}
