<?php

namespace App\Domain\Lc;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\FxRevaluation;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Period-end FX revaluation (P16 LC finance; 06 §12.13; BR-068). Open foreign purchase bills (against the supplier's
 * ledger) and open usance acceptances (against 2302) are restated at closing rates; the difference goes to 6603 in one
 * journal on the revaluation date and is mirrored the next day, so settlements keep booking realised FX at the original
 * rates. The journal's source is the revaluation, so its supplier lines never settle bills (AllocationService).
 */
final class FxRevaluationService
{
    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting, private readonly AuditLogger $audit,
        private readonly LcPostingService $lcPosting) {}

    /** @return array{lines: list<array>, total: Money, builder: JournalBuilder} */
    private function compute(string $date, array $rates): array
    {
        $closing = [];
        foreach ($rates as $r) {
            $closing[strtoupper($r['currency_code'])] = (string) $r['rate'];
        }
        $lines = [];
        $total = Money::zero();
        $b = new JournalBuilder;
        $fx = $this->mapper->ledgerFor('fx_gain_loss');
        $post = function (int $ledgerId, Money $diff, string $ref) use ($b, $fx) {
            $meta = ['against_reference' => $ref];
            if ($diff->isPositive()) { // the liability grew in taka: loss
                $b->debit($fx, $diff, $meta)->credit($ledgerId, $diff, $meta);
            } elseif ($diff->isNegative()) {
                $b->debit($ledgerId, $diff->negated(), $meta)->credit($fx, $diff->negated(), $meta);
            }
        };

        // open foreign purchase bills: the taka balance is held at the bill rate, so foreign outstanding = balance / rate
        $bills = DB::table('purchase_bills')->where('company_id', app(CurrentCompany::class)->id())->where('status', 'posted')->where('balance_due', '>', 0)
            ->whereNotNull('currency_code')->where('currency_code', '!=', 'BDT')->where('bill_date', '<=', $date)->orderBy('bill_date')->orderBy('id')
            ->get(['id', 'number', 'party_id', 'currency_code', 'exchange_rate', 'balance_due', 'letter_of_credit_id']);
        foreach ($bills as $bill) {
            $rate = $closing[strtoupper($bill->currency_code)] ?? null;
            if ($rate === null || ! BigDecimal::of((string) $bill->exchange_rate)->isPositive()) {
                continue;
            }
            $book = Money::of((string) $bill->balance_due);
            $foreign = Money::of($book->toBigDecimal()->dividedBy((string) $bill->exchange_rate, 2, RoundingMode::HalfUp));
            $diff = Money::of($foreign->toBigDecimal()->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp))->minus($book);
            $party = Party::withTrashed()->find($bill->party_id);
            $lines[] = ['source' => 'purchase_bill', 'purchase_bill_id' => (int) $bill->id, 'letter_of_credit_id' => $bill->letter_of_credit_id ? (int) $bill->letter_of_credit_id : null,
                'party_id' => (int) $bill->party_id, 'reference' => $bill->number, 'party_name' => $party?->name, 'currency_code' => strtoupper($bill->currency_code),
                'foreign_outstanding' => (string) $foreign, 'book_amount' => (string) $book, 'book_rate' => (string) BigDecimal::of((string) $bill->exchange_rate)->toScale(6),
                'closing_rate' => (string) BigDecimal::of($rate)->toScale(6), 'difference' => (string) $diff];
            if (! $diff->isZero()) {
                $post((int) $party->ledger_id, $diff, $bill->number);
            }
            $total = $total->plus($diff);
        }

        // open usance acceptances: accepted − settled foreign, carried at the acceptances' taka (2302 tagged with the LC)
        $lcs = DB::table('lc_payments')->where('company_id', app(CurrentCompany::class)->id())->where('status', 'posted')->where('payment_kind', 'usance_acceptance')
            ->where('payment_date', '<=', $date)->distinct()->pluck('letter_of_credit_id');
        $acceptance = $this->mapper->ledgerFor('lc_acceptance');
        foreach ($lcs as $lcId) {
            $lc = DB::table('letters_of_credit')->where('id', $lcId)->first(['id', 'lc_number', 'currency_code', 'party_id']);
            $rate = $closing[strtoupper($lc->currency_code)] ?? null;
            if ($rate === null) {
                continue;
            }
            $sum = fn (string $kind) => Money::of((string) DB::table('lc_payments')->where('letter_of_credit_id', $lcId)->where('status', 'posted')->where('payment_kind', $kind)->sum('amount_foreign'));
            $foreign = $sum('usance_acceptance')->minus($sum('usance_settlement'));
            $book = $this->lcPosting->tagged((int) $lcId, 'lc_acceptance')->negated();
            if (! $foreign->isPositive() || ! $book->isPositive()) {
                continue;
            }
            $diff = Money::of($foreign->toBigDecimal()->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp))->minus($book);
            $lines[] = ['source' => 'lc_acceptance', 'purchase_bill_id' => null, 'letter_of_credit_id' => (int) $lcId, 'party_id' => (int) $lc->party_id, 'reference' => $lc->lc_number,
                'party_name' => Party::withTrashed()->find($lc->party_id)?->name, 'currency_code' => strtoupper($lc->currency_code), 'foreign_outstanding' => (string) $foreign,
                'book_amount' => (string) $book, 'book_rate' => (string) $book->toBigDecimal()->dividedBy($foreign->toBigDecimal(), 6, RoundingMode::HalfUp),
                'closing_rate' => (string) BigDecimal::of($rate)->toScale(6), 'difference' => (string) $diff];
            if (! $diff->isZero()) {
                // not tagged with the LC: the LC's own 2302 balance (acceptance carrying, BR-043) stays at the acceptance rates
                $post((int) $acceptance->id, $diff, $lc->lc_number);
            }
            $total = $total->plus($diff);
        }

        return ['lines' => $lines, 'total' => $total, 'builder' => $b, 'closing' => $closing];
    }

    private function assertDate(string $date): void
    {
        if ($date > CarbonImmutable::now('Asia/Dhaka')->toDateString()) {
            throw new ApiProblem('validation_failed', errors: ['revaluation_date' => ['Revalue on today or an earlier date']]);
        }
    }

    public function preview(array $in): array
    {
        $this->assertDate($in['revaluation_date']);
        $c = $this->compute($in['revaluation_date'], $in['rates']);
        if (! $c['lines']) {
            throw new ApiProblem('nothing_to_revalue', 'No open foreign bills or acceptances in the currencies given on this date.');
        }
        $journal = [];
        foreach ($c['builder']->totalDebit()->isPositive() ? $c['builder']->lines() : [] as $l) {
            $g = DB::table('ledgers')->where('id', $l['ledger_id'])->first(['code', 'name']);
            $journal[] = ['ledger_id' => $l['ledger_id'], 'ledger_code' => (string) $g->code, 'ledger_name' => (string) $g->name, 'debit' => (string) $l['debit'], 'credit' => (string) $l['credit']];
        }

        return ['id' => null, 'status' => 'preview', 'revaluation_date' => $in['revaluation_date'], 'reversal_date' => CarbonImmutable::parse($in['revaluation_date'])->addDay()->toDateString(),
            'rates' => $c['closing'], 'total_difference' => (string) $c['total'], 'lines' => $c['lines'], 'journal' => $journal,
            'warnings' => $c['total']->isZero() ? [['code' => 'NOTHING_TO_ALLOCATE', 'message' => 'The closing rates equal the book rates; nothing would post.']] : [], 'created_at' => null];
    }

    public function post(array $in): FxRevaluation
    {
        $this->assertDate($in['revaluation_date']);

        return DB::transaction(function () use ($in) {
            $date = $in['revaluation_date'];
            $c = $this->compute($date, $in['rates']);
            if (! $c['lines'] || ! $c['builder']->totalDebit()->isPositive()) {
                throw new ApiProblem('nothing_to_revalue', 'Nothing to revalue: no open foreign items, or the closing rates equal the book rates.');
            }
            $next = CarbonImmutable::parse($date)->addDay()->toDateString();
            $r = new FxRevaluation;
            $r->forceFill(['revaluation_date' => $date, 'reversal_date' => $next, 'rates' => $c['closing'], 'total_difference' => (string) $c['total'],
                'status' => 'posted', 'note' => $in['note'] ?? null])->save();
            $now = now();
            DB::table('fx_revaluation_lines')->insert(array_map(fn ($l) => ['fx_revaluation_id' => $r->id, 'source' => $l['source'], 'purchase_bill_id' => $l['purchase_bill_id'],
                'letter_of_credit_id' => $l['letter_of_credit_id'], 'party_id' => $l['party_id'], 'currency_code' => $l['currency_code'], 'foreign_outstanding' => $l['foreign_outstanding'],
                'book_rate' => $l['book_rate'], 'closing_rate' => $l['closing_rate'], 'difference' => $l['difference'], 'created_at' => $now, 'updated_at' => $now], $c['lines']));
            $entry = $this->posting->persist($c['builder'], $r, 'journal', $date, null, 'FX revaluation at closing rates: '.$this->ratesText($c['closing']), 'FX revaluation');
            $reversal = $this->lcPosting->mirror($entry, $next, 'Automatic reversal of the FX revaluation of '.$date);
            $r->forceFill(['journal_entry_id' => $entry->id, 'reversal_entry_id' => $reversal->id])->save();
            $this->audit->record('posted', $r, null, ['date' => $date, 'rates' => $c['closing'], 'total' => (string) $c['total']], type: 'fx_revaluations');

            return $r->refresh();
        });
    }

    /**
     * Reverses a revaluation as a whole (wrong rates). Its entry already has its mirror (the next-day reversal), so each of the
     * two gets a counter-entry on its own date, sourced to the revaluation (never settling bills); neither date keeps an effect.
     */
    public function reverse(int $id, string $reason): FxRevaluation
    {
        return DB::transaction(function () use ($id, $reason) {
            $r = FxRevaluation::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'posted') {
                throw new ApiProblem('already_reversed', 'This revaluation is already reversed.');
            }
            foreach ([$r->journal_entry_id, $r->reversal_entry_id] as $entryId) {
                $e = JournalEntry::find($entryId);
                if ($e) {
                    $this->posting->persist(LcPostingService::mirrorBuilder($e), $r, 'journal', $e->entry_date->toDateString(), null,
                        "Reversal of the FX revaluation of {$r->revaluation_date->toDateString()}: {$reason}", 'FX revaluation');
                }
            }
            $r->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $r, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason], type: 'fx_revaluations');

            return $r->refresh();
        });
    }

    private function ratesText(array $closing): string
    {
        return implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($closing), $closing));
    }

    public function resource(FxRevaluation $r): array
    {
        $lines = DB::table('fx_revaluation_lines as l')->leftJoin('purchase_bills as b', 'b.id', '=', 'l.purchase_bill_id')
            ->leftJoin('letters_of_credit as c', 'c.id', '=', 'l.letter_of_credit_id')->leftJoin('parties as p', 'p.id', '=', 'l.party_id')
            ->where('l.fx_revaluation_id', $r->id)->orderBy('l.id')
            ->get(['l.source', 'l.purchase_bill_id', 'l.letter_of_credit_id', 'l.party_id', 'p.name as party_name', 'b.number as bill_number', 'c.lc_number', 'l.currency_code',
                'l.foreign_outstanding', 'l.book_rate', 'l.closing_rate', 'l.difference'])
            ->map(fn ($l) => ['source' => $l->source, 'purchase_bill_id' => $l->purchase_bill_id, 'letter_of_credit_id' => $l->letter_of_credit_id, 'party_id' => $l->party_id,
                'reference' => $l->source === 'purchase_bill' ? $l->bill_number : $l->lc_number, 'party_name' => $l->party_name, 'currency_code' => $l->currency_code,
                'foreign_outstanding' => (string) Money::of((string) $l->foreign_outstanding), 'book_rate' => (string) $l->book_rate, 'closing_rate' => (string) $l->closing_rate,
                'difference' => (string) Money::of((string) $l->difference)])->all();
        $journal = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $r->journal_entry_id)->orderBy('l.id')
            ->get(['l.ledger_id', 'g.code', 'g.name', 'l.debit', 'l.credit'])->map(fn ($l) => ['ledger_id' => $l->ledger_id, 'ledger_code' => $l->code, 'ledger_name' => $l->name,
                'debit' => (string) Money::of((string) $l->debit), 'credit' => (string) Money::of((string) $l->credit)])->all();

        return ['id' => $r->id, 'status' => $r->status, 'revaluation_date' => $r->revaluation_date->toDateString(), 'reversal_date' => $r->reversal_date->toDateString(),
            'rates' => $r->rates, 'total_difference' => (string) $r->total_difference, 'lines' => $lines, 'journal' => $journal, 'warnings' => [],
            'created_at' => $r->created_at?->toIso8601String()];
    }
}
