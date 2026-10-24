<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\Money;
use App\Models\Ledger;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\Log;

/**
 * Builds the lines of one journal entry (06 §1–§4; BR-001, BR-002; backlog P4.1-BE-04). Posting services add debits
 * and credits from mappings and master data; the builder
 *  - turns a negative amount into the opposite side (e.g. a negative rounding adjustment is a debit),
 *  - drops zero amounts,
 *  - merges lines with the same ledger, side and tags (tax rule, warehouse, LC, foreign currency and rate, reference),
 *  - and on lines() asserts Σ debit = Σ credit > 0; a failure is unbalanced_entry and is logged as a bug.
 * Each resulting line has exactly one side > 0 (the DB CHECKs enforce it again).
 */
final class JournalBuilder
{
    /** Tags that keep otherwise identical lines apart. */
    private const TAGS = ['tax_rule_id', 'warehouse_id', 'letter_of_credit_id', 'foreign_currency_code', 'exchange_rate', 'against_reference'];

    /** @var array<string, array{ledger_id: int, side: string, amount: Money, meta: array}> */
    private array $lines = [];

    /** @param  array{line_narration?: string, tax_rule_id?: int, warehouse_id?: int, letter_of_credit_id?: int, foreign_currency_code?: string, foreign_amount?: Money|string, exchange_rate?: string, against_reference?: string}  $meta */
    public function debit(Ledger|int $ledger, Money $amount, array $meta = []): self
    {
        return $this->add($ledger, $amount->isNegative() ? 'credit' : 'debit', $amount->abs(), $meta);
    }

    public function credit(Ledger|int $ledger, Money $amount, array $meta = []): self
    {
        return $this->add($ledger, $amount->isNegative() ? 'debit' : 'credit', $amount->abs(), $meta);
    }

    public function totalDebit(): Money
    {
        return Money::sum(array_map(fn ($l) => $l['amount'], array_filter($this->lines, fn ($l) => $l['side'] === 'debit')));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(fn ($l) => $l['amount'], array_filter($this->lines, fn ($l) => $l['side'] === 'credit')));
    }

    /**
     * The merged, numbered lines of a balanced entry, debits first. Throws unbalanced_entry when the sides differ or
     * nothing is left to post.
     *
     * @return list<array{line_no: int, ledger_id: int, debit: Money, credit: Money, meta: array}>
     */
    public function lines(): array
    {
        $dr = $this->totalDebit();
        $cr = $this->totalCredit();
        if (! $dr->isEqualTo($cr) || $dr->isZero()) {
            Log::error('Unbalanced journal entry built (bug)', ['debit' => (string) $dr, 'credit' => (string) $cr, 'lines' => $this->debugLines()]);
            throw new ApiProblem('unbalanced_entry', "Debits {$dr} and credits {$cr} do not balance.", extra: ['total_debit' => (string) $dr, 'total_credit' => (string) $cr]);
        }
        $ordered = array_merge(
            array_values(array_filter($this->lines, fn ($l) => $l['side'] === 'debit')),
            array_values(array_filter($this->lines, fn ($l) => $l['side'] === 'credit')),
        );
        $out = [];
        foreach ($ordered as $i => $l) {
            $out[] = ['line_no' => $i + 1, 'ledger_id' => $l['ledger_id'],
                'debit' => $l['side'] === 'debit' ? $l['amount'] : Money::zero(), 'credit' => $l['side'] === 'credit' ? $l['amount'] : Money::zero(),
                'meta' => $l['meta']];
        }

        return $out;
    }

    private function add(Ledger|int $ledger, string $side, Money $amount, array $meta): self
    {
        if ($amount->isZero()) {
            return $this;
        }
        $ledgerId = $ledger instanceof Ledger ? $ledger->id : $ledger;
        $tags = array_map(fn ($k) => isset($meta[$k]) ? (string) $meta[$k] : '', self::TAGS);
        $key = $ledgerId.'|'.$side.'|'.implode('|', $tags);
        if (isset($this->lines[$key])) {
            $this->lines[$key]['amount'] = $this->lines[$key]['amount']->plus($amount);
            if (isset($meta['foreign_amount'])) {
                $this->lines[$key]['meta']['foreign_amount'] = Money::of($this->lines[$key]['meta']['foreign_amount'] ?? '0')->plus(Money::of($meta['foreign_amount']));
            }
        } else {
            if (isset($meta['foreign_amount'])) {
                $meta['foreign_amount'] = Money::of($meta['foreign_amount']);
            }
            $this->lines[$key] = ['ledger_id' => $ledgerId, 'side' => $side, 'amount' => $amount, 'meta' => $meta];
        }

        return $this;
    }

    private function debugLines(): array
    {
        return array_values(array_map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'side' => $l['side'], 'amount' => (string) $l['amount']], $this->lines));
    }
}
