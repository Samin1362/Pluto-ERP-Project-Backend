<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\Money;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances of parties, money accounts and later ledgers (06 §16; BR-028; backlog P6.1-BE-01/06). The opening
 * is an opening_balance entry dated the financial year's start, the ledger against opening_balance_adjustment (3301):
 * a positive amount is a debit on the ledger, a negative one a credit. It can change only while the opening entry is
 * the ledger's only entry — the old entry is reversed and a new one posted; after that it is locked (BR-028).
 */
final class OpeningBalanceService
{
    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting) {}

    /** The current opening of `$ledgerId` set by `$source` (Dr − Cr of its live opening entries). */
    public function current(Model $source, int $ledgerId): Money
    {
        return Money::sum($this->entries($source)->flatMap->lines->where('ledger_id', $ledgerId)
            ->map(fn ($l) => Money::of((string) $l->debit)->minus((string) $l->credit)));
    }

    /** True once the ledger has any entry besides the source's own opening entries. */
    public function locked(Model $source, int $ledgerId): bool
    {
        return DB::table('journal_lines')->where('ledger_id', $ledgerId)->whereNotIn('journal_entry_id', $this->entries($source)->pluck('id'))->exists();
    }

    /**
     * Sets the opening to `$amount` (Dr positive). No change → nothing posted.
     *
     * @param  string  $field  the request field reported when the opening is locked
     */
    public function set(Model $source, int $ledgerId, Money $amount, string $label, ?string $onDate = null, string $field = 'opening_balance'): void
    {
        if ($this->current($source, $ledgerId)->isEqualTo($amount)) {
            return;
        }
        if ($this->locked($source, $ledgerId)) {
            throw new ApiProblem('validation_failed', errors: [$field => ['The opening balance is locked: this account already has entries (BR-028)']]);
        }
        foreach ($this->entries($source) as $entry) {
            $b = new JournalBuilder;
            foreach ($entry->lines as $l) {
                $b->debit($l->ledger_id, Money::of((string) $l->credit))->credit($l->ledger_id, Money::of((string) $l->debit));
            }
            $this->posting->persist($b, $source, 'reversal', $entry->entry_date, null, "Opening balance of {$label} replaced", null, null, $entry->id);
            $entry->forceFill(['status' => 'reversed'])->save();
        }
        if ($amount->isZero()) {
            return;
        }
        $day = $onDate ?? CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $fy = FiscalYear::whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day)->first()
            ?? throw new ApiProblem('period_locked', "No financial year covers {$day}.", errors: ['opening_date' => ['No financial year covers this date']]);
        $b = (new JournalBuilder)->debit($ledgerId, $amount)->credit($this->mapper->ledgerFor('opening_balance_adjustment'), $amount); // negative = the other side
        $this->posting->persist($b, $source, 'opening_balance', $fy->start_date, null, "Opening balance of {$label}");
    }

    private function entries(Model $source)
    {
        return JournalEntry::with('lines')->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
            ->where('entry_type', 'opening_balance')->where('status', 'posted')->get();
    }
}
