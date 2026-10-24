<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Persists a balanced journal as a posted entry (06 §4; BR-001, BR-003, BR-004, BR-009; backlog P4.1-BE-05). Called
 * inside the posting service's transaction, after stock and business checks:
 *  - the date must be in an open period (PeriodGuard), whose year and period go on the entry;
 *  - a document's own entry uses the document's number (BILL-…, INV-…); every other system entry takes the next
 *    system_journal number of that year (SJ-2026-0001, review R-06);
 *  - the header (status posted, totals) and its lines (is_posted = 1, entry date copied) are written together and
 *    linked to the source document. After that the DB triggers keep them immutable (06 §15).
 */
final class PostingService
{
    public function __construct(private readonly PeriodGuard $periods, private readonly DocumentNumberService $numbers) {}

    /**
     * @param  string  $entryType  journal_entries.entry_type (purchase_bill, cancellation, opening_balance, …)
     * @param  string|null  $number  the document's own number; null = next system journal number
     */
    public function persist(JournalBuilder $builder, ?Model $source, string $entryType, CarbonInterface|string $date, ?string $number = null,
        ?string $narration = null, ?string $reference = null, ?int $branchId = null, ?int $reversesEntryId = null): JournalEntry
    {
        return DB::transaction(function () use ($builder, $source, $entryType, $date, $number, $narration, $reference, $branchId, $reversesEntryId) {
            $lines = $builder->lines(); // throws unbalanced_entry before anything is written
            $period = $this->periods->assertOpen($date);
            $entryDate = $date instanceof CarbonInterface ? $date->toDateString() : $date;
            $total = Money::sum(array_map(fn ($l) => $l['debit'], $lines));

            $entry = JournalEntry::create([
                'branch_id' => $branchId, 'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
                'entry_type' => $entryType, 'voucher_number' => $number ?? $this->numbers->next('system_journal', $period->fiscal_year_id)['number'],
                'entry_date' => $entryDate, 'reference' => $reference, 'narration' => $narration, 'status' => 'posted',
                'total_debit' => $total, 'total_credit' => $total,
                'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(), 'reverses_entry_id' => $reversesEntryId,
                'is_system_generated' => $number === null, 'posted_at' => now(), 'posted_by' => Auth::id(),
            ]);
            foreach ($lines as $l) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id, 'line_no' => $l['line_no'], 'ledger_id' => $l['ledger_id'],
                    'debit' => $l['debit'], 'credit' => $l['credit'], 'entry_date' => $entryDate, 'is_posted' => true,
                ] + array_intersect_key($l['meta'], array_flip(['line_narration', 'against_reference', 'tax_rule_id', 'foreign_currency_code',
                    'foreign_amount', 'exchange_rate', 'warehouse_id', 'letter_of_credit_id'])));
            }

            return $entry->load('lines');
        });
    }
}
