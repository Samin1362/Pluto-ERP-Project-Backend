<?php

namespace Tests\Unit\Domain;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Shared\Money;
use App\Support\Problem\ApiProblem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Log;

/** JournalBuilder (BR-001, BR-002; backlog P4.1-BE-04). Boots the app (logging) but uses no database. */
class JournalBuilderTest extends TestCase
{
    private function m(string $v): Money
    {
        return Money::of($v);
    }

    public function test_tc_acc_001_unequal_sides_throw_unbalanced_entry_and_are_logged_as_a_bug(): void
    {
        Log::spy();
        $b = (new JournalBuilder)->debit(10, $this->m('626000.00'))->credit(20, $this->m('625999.99'));
        try {
            $b->lines();
            $this->fail('unbalanced entry accepted');
        } catch (ApiProblem $e) {
            $this->assertSame('unbalanced_entry', $e->errorCode);
            $this->assertSame(['total_debit' => '626000.00', 'total_credit' => '625999.99'], $e->extra);
        }
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Unbalanced'));
    }

    public function test_an_empty_or_all_zero_entry_is_unbalanced_too(): void
    {
        Log::spy();
        $this->expectException(ApiProblem::class);
        (new JournalBuilder)->debit(10, Money::zero())->credit(20, Money::zero())->lines();
    }

    public function test_bill_2026_0224_dr_inventory_cr_supplier(): void
    {
        $lines = (new JournalBuilder)->debit(1302, $this->m('626000.00'), ['warehouse_id' => 2])->credit(2104, $this->m('626000.00'))->lines();
        $this->assertCount(2, $lines);
        $this->assertSame([1, 1302, '626000.00', '0.00', 2], [$lines[0]['line_no'], $lines[0]['ledger_id'], (string) $lines[0]['debit'], (string) $lines[0]['credit'], $lines[0]['meta']['warehouse_id']]);
        $this->assertSame([2, 2104, '0.00', '626000.00'], [$lines[1]['line_no'], $lines[1]['ledger_id'], (string) $lines[1]['debit'], (string) $lines[1]['credit']]);
    }

    public function test_same_ledger_side_and_tags_merge_different_tags_do_not(): void
    {
        // 06 §5: five stocked lines' net to 4101 merge into one credit; the service line's own ledger stays apart.
        $b = new JournalBuilder;
        $b->debit(1201, $this->m('118450.00'));
        foreach (['37600', '28080', '30600', '21120', '750'] as $net) {
            $b->credit(4101, $this->m($net));
        }
        $b->credit(4201, $this->m('300'));
        $lines = $b->lines();
        $this->assertCount(3, $lines);
        $this->assertSame('118150.00', (string) $lines[1]['credit']);

        // Tax lines of two rules on the same ledger stay apart; warehouses too.
        $b = (new JournalBuilder)->debit(5001, $this->m('100'))->credit(2205, $this->m('60'), ['tax_rule_id' => 1])->credit(2205, $this->m('40'), ['tax_rule_id' => 2]);
        $this->assertCount(3, $b->lines());
        $b = (new JournalBuilder)->credit(1301, $this->m('70'), ['warehouse_id' => 1])->credit(1301, $this->m('30'), ['warehouse_id' => 2])
            ->credit(1301, $this->m('5'), ['warehouse_id' => 1])->debit(5001, $this->m('105'));
        $lines = $b->lines();
        $this->assertCount(3, $lines);
        $this->assertSame(['5001', '75.00', '30.00'], [(string) $lines[0]['ledger_id'], (string) $lines[1]['credit'], (string) $lines[2]['credit']], 'debits first');
    }

    public function test_negative_amounts_flip_side_zero_lines_drop_and_each_line_has_one_side(): void
    {
        // Rounding −0.40 credited is a debit of 0.40 (06 §5: "debit if negative").
        $lines = (new JournalBuilder)->debit(1201, $this->m('118449.60'))->credit(4101, $this->m('118450.00'))->credit(6799, $this->m('-0.40'))
            ->debit(9999, Money::zero())->lines();
        $this->assertCount(3, $lines);
        $rounding = collect($lines)->firstWhere('ledger_id', 6799);
        $this->assertSame(['0.40', '0.00'], [(string) $rounding['debit'], (string) $rounding['credit']]);
        foreach ($lines as $l) {
            $this->assertTrue($l['debit']->isZero() xor $l['credit']->isZero(), 'exactly one side > 0 (BR-002)');
        }
    }

    public function test_foreign_amounts_add_up_when_lines_merge(): void
    {
        $meta = ['foreign_currency_code' => 'USD', 'exchange_rate' => '110.500000'];
        $lines = (new JournalBuilder)->debit(1404, $this->m('1105.00'), $meta + ['foreign_amount' => '10.00'])->debit(1404, $this->m('552.50'), $meta + ['foreign_amount' => '5.00'])
            ->credit(2104, $this->m('1657.50'))->lines();
        $this->assertSame(['1657.50', '15.00'], [(string) $lines[0]['debit'], (string) $lines[0]['meta']['foreign_amount']]);
    }
}
