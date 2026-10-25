<?php

namespace Tests\Unit;

use App\Domain\Settlements\AllocationPlanner;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

/** AllocationPlanner (06 §6; BR-020; backlog P6.1-BE-02). */
class AllocationPlannerTest extends TestCase
{
    private function docs(): array
    {
        return [
            ['id' => 3, 'due_date' => '2026-11-10', 'date' => '2026-10-10', 'balance' => Money::of('50000.00')],
            ['id' => 1, 'due_date' => '2026-10-20', 'date' => '2026-09-20', 'balance' => Money::of('20000.00')],
            ['id' => 2, 'due_date' => '2026-10-20', 'date' => '2026-09-25', 'balance' => Money::of('30000.00')],
            ['id' => 4, 'due_date' => '2026-09-01', 'date' => '2026-08-01', 'balance' => Money::of('0.00')],
        ];
    }

    private function flat(array $plan): array
    {
        return [array_map(fn ($a) => [$a['id'], (string) $a['amount']], $plan['allocations']), (string) $plan['unapplied']];
    }

    public function test_oldest_due_first_then_date_and_never_above_a_balance(): void
    {
        $this->assertSame([[[1, '20000.00'], [2, '30000.00'], [3, '15000.00']], '0.00'], $this->flat((new AllocationPlanner)->plan(Money::of('65000'), $this->docs())));
    }

    public function test_the_excess_stays_unapplied_and_a_small_amount_covers_part_of_one(): void
    {
        $this->assertSame([[[1, '20000.00'], [2, '30000.00'], [3, '50000.00']], '25300.00'], $this->flat((new AllocationPlanner)->plan(Money::of('125300'), $this->docs())));
        $this->assertSame([[[1, '0.50']], '0.00'], $this->flat((new AllocationPlanner)->plan(Money::of('0.50'), $this->docs())));
        $this->assertSame([[], '0.00'], $this->flat((new AllocationPlanner)->plan(Money::zero(), $this->docs())));
        $this->assertSame([[], '100.00'], $this->flat((new AllocationPlanner)->plan(Money::of('100'), [])));
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AllocationPlanner)->plan(Money::of('-1'), $this->docs());
    }
}
