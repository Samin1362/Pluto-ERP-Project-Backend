<?php

namespace App\Domain\Accounting;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\JournalEntry;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reverse a posted voucher (Acc-DlgReverse, H13; 05 postVouchersIdReverse; backlog P7.1-BE-06; BR-003, BR-004,
 * BR-009; GAP-07). One transaction: the original is locked; its live allocations are removed (the bills they settled
 * open again); a reversal entry — the original's lines with the sides swapped, a new number of the same series, dated
 * `reversal_date` in an open period — is posted with reverses_entry_id; the original becomes reversed. Entries made by
 * documents (invoices, bills, openings, …) are corrected through their documents (use_document_cancel). A second
 * reversal is already_reversed (and UNIQUE(reverses_entry_id) backs it up).
 */
final class VoucherReversalService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly AllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    public function reverse(JournalEntry $entry, string $date, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $reason) {
            $entry = JournalEntry::with('lines')->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->source_type !== null || ! in_array($entry->entry_type, VoucherCalculator::ALL_TYPES, true)) {
                throw new ApiProblem('use_document_cancel', "{$entry->voucher_number} was made by a document; cancel that document instead.");
            }
            if ($entry->status === 'reversed' || JournalEntry::where('reverses_entry_id', $entry->id)->exists()) {
                throw new ApiProblem('already_reversed', "{$entry->voucher_number} is already reversed.");
            }
            if ($entry->status !== 'posted') {
                throw new ApiProblem('validation_failed', errors: ['status' => ["{$entry->voucher_number} is {$entry->status}; only a posted voucher is reversed. Delete or edit the draft instead."]]);
            }
            if (CarbonImmutable::parse($date)->lt($entry->entry_date)) {
                throw new ApiProblem('validation_failed', errors: ['reversal_date' => ['The reversal cannot be dated before the voucher ('.$entry->entry_date->format('j M Y').')']]);
            }
            $period = $this->periods->assertOpen($date);

            $released = 0;
            $live = DB::table('bill_allocations')->whereIn('journal_line_id', $entry->lines->pluck('id'))->whereNull('reversed_at')->orderBy('id')->pluck('id');
            foreach ($live as $allocationId) {
                $this->allocations->remove((int) $allocationId);
                $released++;
            }

            $b = new JournalBuilder;
            foreach ($entry->lines as $l) {
                $meta = array_filter(['line_narration' => $l->line_narration, 'against_reference' => $l->against_reference]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            $number = $this->numbers->next(VoucherService::NUMBER_SERIES[$entry->entry_type], $period->fiscal_year_id)['number'];
            $reversal = $this->posting->persist($b, null, 'reversal', $date, $number, "Reversal of {$entry->voucher_number}: {$reason}", $entry->voucher_number,
                $entry->branch_id, $entry->id);
            $entry->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $entry, ['status' => 'posted'], ['status' => 'reversed', 'reversed_by' => $reversal->voucher_number, 'reason' => $reason,
                'allocations_released' => $released], type: 'journal_entries');
            $this->audit->record('created', $reversal, null, ['voucher_number' => $reversal->voucher_number, 'reverses' => $entry->voucher_number], type: 'journal_entries');

            return $reversal;
        }, attempts: 2);
    }
}
