<?php

namespace App\Domain\Settlements;

use App\Domain\Shared\Money;

/**
 * Default allocation of an amount to open documents (06 §6; BR-020; backlog P6.1-BE-02). Pure: oldest due date first
 * (then document date, then id), each document gets at most its open balance, the plan never exceeds the amount;
 * whatever is left stays as an advance on the party ledger.
 */
final class AllocationPlanner
{
    /**
     * @param  list<array{id: int, due_date: string, date: string, balance: Money}>  $documents
     * @return array{allocations: list<array{id: int, amount: Money}>, unapplied: Money}
     */
    public function plan(Money $amount, array $documents): array
    {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('The amount to allocate cannot be negative.');
        }
        usort($documents, fn ($a, $b) => [$a['due_date'], $a['date'], $a['id']] <=> [$b['due_date'], $b['date'], $b['id']]);
        $left = $amount;
        $out = [];
        foreach ($documents as $d) {
            if (! $left->isPositive()) {
                break;
            }
            if (! $d['balance']->isPositive()) {
                continue;
            }
            $take = $d['balance']->isLessThan($left) ? $d['balance'] : $left;
            $out[] = ['id' => $d['id'], 'amount' => $take];
            $left = $left->minus($take);
        }

        return ['allocations' => $out, 'unapplied' => $left];
    }
}
