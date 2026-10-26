<?php

namespace App\Domain\Accounting;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Party;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Journal, payment, receipt and contra vouchers (H9–H12; 05 vouchers; backlog P7.1-BE-04; BR-001, BR-003, BR-004,
 * BR-006, BR-007, BR-009, BR-025, BR-031, BR-053, BR-065). A voucher is saved first as a draft — its JV/PV/RV/CV
 * number reserved in the date's financial year (BR-006), its lines kept unposted (is_posted = 0) — and then:
 *  - post: balanced (unbalanced_entry), open period (period_locked), back-date confirmed (BR-053), money accounts
 *    covered (insufficient_funds, BR-031), and within the submitter's role limit (BR-007) — above it the voucher is
 *    submitted for approval instead (`approval_required` = true, 202). Posting updates the draft's own rows in place
 *    (status posted, lines is_posted = 1), so the number and lines are those the user saw; allocations sent with the
 *    lines then settle the party's bills (BR-020);
 *  - submit: waits for approval (approval_requests, ApprovalService).
 * Drafts and rejected vouchers can be replaced (PUT); only drafts can be deleted, which voids their number (audited).
 * System entries (from documents) are not vouchers here: they change only through their documents.
 */
final class VoucherService
{
    public const NUMBER_SERIES = ['journal' => 'journal', 'payment' => 'payment', 'receipt' => 'receipt', 'contra' => 'contra'];

    public function __construct(
        private readonly VoucherCalculator $calculator,
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly AllocationService $allocations,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{0: JournalEntry, 1: bool} the voucher, and whether it went for approval instead of posting */
    public function create(array $in, CompanyUser $membership): array
    {
        return DB::transaction(function () use ($in, $membership) {
            $calc = $this->calculator->calculate($in, $membership);
            $this->assertCanSave($in, $calc);
            $period = $this->periods->assertOpen($in['date']);
            $entry = new JournalEntry(['fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id, 'entry_type' => $in['entry_type'],
                'voucher_number' => $this->numbers->next(self::NUMBER_SERIES[$in['entry_type']], $period->fiscal_year_id)['number'], 'status' => 'draft',
                'is_system_generated' => false]);
            $this->fill($entry, $in, $calc, $period);
            $this->audit->record('created', $entry, null, ['voucher_number' => $entry->voucher_number, 'entry_type' => $entry->entry_type,
                'amount' => (string) $calc['total_debit'], 'action' => $in['action']], type: 'journal_entries');

            return $this->act($entry, $in, $calc, $membership);
        }, attempts: 2);
    }

    /** PUT: replaces a draft or rejected voucher; the number stays. */
    public function update(JournalEntry $entry, array $in, int $expectedVersion, CompanyUser $membership): array
    {
        return DB::transaction(function () use ($entry, $in, $expectedVersion, $membership) {
            $entry = $this->lockVoucher($entry->id);
            if (! in_array($entry->status, ['draft', 'rejected'], true)) {
                throw new ApiProblem('not_draft', "{$entry->voucher_number} is {$entry->status}; only a draft or rejected voucher can be changed.");
            }
            if ((int) $entry->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $entry->lock_version]);
            }
            if ($in['entry_type'] !== $entry->entry_type) {
                throw new ApiProblem('validation_failed', errors: ['entry_type' => ['The voucher type cannot change; delete the draft and enter a new voucher']]);
            }
            $calc = $this->calculator->calculate($in, $membership);
            $this->assertCanSave($in, $calc);
            $period = $this->periods->assertOpen($in['date']);
            $old = ['entry_date' => $entry->entry_date->toDateString(), 'narration' => $entry->narration, 'amount' => (string) $entry->total_debit];
            JournalLine::where('journal_entry_id', $entry->id)->delete();
            DB::table('payment_details')->where('journal_entry_id', $entry->id)->delete();
            $entry->status = 'draft';
            $entry->lock_version = (int) $entry->lock_version + 1;
            $this->fill($entry, $in, $calc, $period);
            $this->audit->record('updated', $entry, $old, ['entry_date' => $in['date'], 'narration' => $entry->narration, 'amount' => (string) $calc['total_debit']], type: 'journal_entries');

            return $this->act($entry, $in, $calc, $membership);
        }, attempts: 2);
    }

    /** POST /vouchers/{id}/post. A posted voucher answers as it is (BR-009). */
    public function post(JournalEntry $entry, array $options, CompanyUser $membership): array
    {
        return DB::transaction(function () use ($entry, $options, $membership) {
            $entry = $this->lockVoucher($entry->id);
            if ($entry->status === 'posted') {
                return [$entry, false];
            }
            if ($entry->status === 'submitted') {
                throw new ApiProblem('invalid_transition', "{$entry->voucher_number} is waiting for approval; it posts when it is approved.");
            }
            if (! in_array($entry->status, ['draft', 'approved'], true)) {
                throw new ApiProblem('invalid_transition', "{$entry->voucher_number} is {$entry->status}; edit it before posting.");
            }

            return $this->postOrSubmit($entry, (bool) ($options['confirm_backdated'] ?? false), $membership, [], $entry->status === 'approved');
        }, attempts: 2);
    }

    /** POST /vouchers/{id}/submit. */
    public function submit(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = $this->lockVoucher($entry->id);
            if (! in_array($entry->status, ['draft', 'rejected'], true)) {
                throw new ApiProblem('invalid_transition', "{$entry->voucher_number} is {$entry->status}; only a draft or rejected voucher can be submitted.");
            }
            $this->assertBalanced($entry);
            $this->toApproval($entry);

            return $entry;
        });
    }

    /** DELETE: drafts only (BR-025); the voided number is audited (BR-006). */
    public function delete(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = $this->lockVoucher($entry->id);
            if ($entry->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$entry->voucher_number} is {$entry->status}; only a draft can be deleted. A posted voucher is reversed.");
            }
            $this->approvals->cancelPending($entry);
            $this->audit->record('deleted', $entry, ['voucher_number' => $entry->voucher_number, 'amount' => (string) $entry->total_debit], ['number_voided' => $entry->voucher_number],
                type: 'journal_entries');
            JournalLine::where('journal_entry_id', $entry->id)->delete();
            DB::table('payment_details')->where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
        });
    }

    /**
     * Posts an approved or within-limit voucher in place; above the limit it is submitted instead (P7.1-BE-05 calls
     * this with $approved = true once the approver agrees).
     *
     * @param  list<array>  $allocations  per line index, from the create/replace input
     * @return array{0: JournalEntry, 1: bool}
     */
    public function postOrSubmit(JournalEntry $entry, bool $confirmBackdated, ?CompanyUser $membership, array $allocations = [], bool $approved = false): array
    {
        $this->assertBalanced($entry);
        $lines = JournalLine::where('journal_entry_id', $entry->id)->orderBy('line_no')->get();
        $amount = Money::of((string) $entry->total_debit);
        if (! $approved && $this->calculator->approval($entry->entry_type, $amount, $membership)[0]) {
            $this->toApproval($entry);

            return [$entry, true];
        }
        $date = $entry->entry_date->toDateString();
        $period = $this->periods->assertOpen($date);
        if (! $confirmBackdated && ! $approved && $this->calculator->isBackdated($date)) {
            throw new ApiProblem('backdated_confirmation_required', 'The voucher is dated '.$entry->entry_date->format('j M Y').', older than the back-date window; confirm to post it.',
                errors: ['confirm_backdated' => ['Confirm the back-dated voucher']]);
        }
        $short = $this->calculator->funds($lines->map(fn ($l) => ['ledger_id' => $l->ledger_id, 'debit' => $l->debit, 'credit' => $l->credit])->all());
        if ($short !== []) {
            throw new ApiProblem('insufficient_funds', $short[0]['message'].' (BR-031).');
        }
        $entry->forceFill(['status' => 'posted', 'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
            'posted_at' => now(), 'posted_by' => auth()->id(), 'lock_version' => (int) $entry->lock_version + 1])->save();
        JournalLine::where('journal_entry_id', $entry->id)->update(['is_posted' => true, 'entry_date' => $date]);
        $this->settle($entry, $lines, $allocations, $date);
        $this->audit->record('posted', $entry, ['status' => 'draft'], ['status' => 'posted', 'voucher_number' => $entry->voucher_number], type: 'journal_entries');

        return [$entry->refresh(), false];
    }

    /** Saves the header, lines and payment detail of a draft from the calculation. */
    private function fill(JournalEntry $entry, array $in, array $calc, $period): void
    {
        $entry->fill(['fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id, 'entry_date' => $in['date'],
            'reference' => $in['reference'] ?? null, 'narration' => $in['narration'] ?? null, 'template_key' => $in['template_key'] ?? null,
            'total_debit' => $calc['total_debit'], 'total_credit' => $calc['total_credit']]);
        $entry->save();
        foreach (array_values($calc['lines']) as $i => $l) {
            JournalLine::create(['journal_entry_id' => $entry->id, 'line_no' => $i + 1, 'ledger_id' => $l['ledger_id'], 'debit' => $l['debit'], 'credit' => $l['credit'],
                'entry_date' => $in['date'], 'is_posted' => false, 'line_narration' => $l['line_narration'], 'against_reference' => $l['against_reference']]);
        }
        if ($calc['account']) {
            $p = $in['payment'];
            $party = isset($p['party_id']) ? Party::find($p['party_id']) : $this->partyOfLines($calc['lines']);
            $partyAmount = $party ? Money::sum(array_map(fn ($l) => (int) $l['ledger_id'] === (int) $party->ledger_id ? $l['debit']->plus($l['credit']) : Money::zero(), $calc['lines'])) : Money::zero();
            $gross = $in['entry_type'] === 'receipt' ? $calc['total_credit'] : $calc['total_debit']->minus($calc['charge']);
            DB::table('payment_details')->insert(['company_id' => $entry->company_id, 'journal_entry_id' => $entry->id,
                'direction' => ['payment' => 'out', 'receipt' => 'in', 'contra' => 'transfer'][$in['entry_type']], 'party_id' => $party?->id,
                'payee_text' => $p['payee_text'] ?? null, 'money_account_id' => $calc['account']->id, 'counter_money_account_id' => $calc['counter']?->id,
                'method' => $p['method'], 'cheque_number' => $p['cheque_number'] ?? null, 'cheque_date' => $p['cheque_date'] ?? null, 'drawn_on' => $p['drawn_on'] ?? null,
                'transaction_reference' => $p['transaction_reference'] ?? null, 'amount' => (string) $gross, 'bank_charge' => (string) $calc['charge'],
                'handed_to' => $p['handed_to'] ?? null, 'unapplied_amount' => (string) $partyAmount,
                'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Runs the requested action on a freshly saved draft. */
    private function act(JournalEntry $entry, array $in, array $calc, CompanyUser $membership): array
    {
        return match ($in['action']) {
            'draft' => [$entry->refresh(), false],
            'submit' => (function () use ($entry) {
                $this->assertBalanced($entry);
                $this->toApproval($entry);

                return [$entry->refresh(), true];
            })(),
            'post' => $this->postOrSubmit($entry, (bool) ($in['confirm_backdated'] ?? false), $membership, array_column($calc['lines'], 'allocations')),
        };
    }

    private function assertCanSave(array $in, array $calc): void
    {
        if ($in['action'] !== 'post' && collect($calc['lines'])->contains(fn ($l) => ! empty($l['allocations']))) {
            throw new ApiProblem('validation_failed', errors: ['lines' => ['Bills are settled when the voucher posts; remove the allocations to save a draft']]);
        }
        if ($in['action'] !== 'draft' && ! $calc['balanced']) {
            throw new ApiProblem('unbalanced_entry', "Debits {$calc['total_debit']} and credits {$calc['total_credit']} differ by {$calc['difference']}.",
                extra: ['total_debit' => (string) $calc['total_debit'], 'total_credit' => (string) $calc['total_credit']]);
        }
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $dr = Money::of((string) $entry->total_debit);
        $cr = Money::of((string) $entry->total_credit);
        if (! $dr->isEqualTo($cr) || ! $dr->isPositive()) {
            throw new ApiProblem('unbalanced_entry', "Debits {$dr} and credits {$cr} do not balance.", extra: ['total_debit' => (string) $dr, 'total_credit' => (string) $cr]);
        }
    }

    private function toApproval(JournalEntry $entry): void
    {
        $entry->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => auth()->id(), 'lock_version' => (int) $entry->lock_version + 1])->save();
        $this->approvals->request($entry, Money::of((string) $entry->total_debit));
        $this->audit->record('submitted', $entry, null, ['voucher_number' => $entry->voucher_number, 'amount' => (string) $entry->total_debit], type: 'journal_entries');
    }

    /** Allocations sent with the lines settle the party's posted bills (BR-020), in document id order. */
    private function settle(JournalEntry $entry, $lines, array $allocations, string $date): void
    {
        foreach ($allocations as $i => $rows) {
            if (empty($rows)) {
                continue;
            }
            $line = $lines[$i] ?? null;
            $party = $line ? Party::where('ledger_id', $line->ledger_id)->first() : null;
            if (! $party) {
                throw new ApiProblem('validation_failed', errors: ["lines.{$i}.allocations" => ['Only a customer or supplier line can settle bills']]);
            }
            $type = $party->party_type === 'customer' ? 'sales_invoice' : 'purchase_bill';
            foreach (collect($rows)->sortBy('document_id') as $a) {
                $this->allocations->allocate($line->id, $type, (int) $a['document_id'], Money::of($a['amount']), $date);
            }
        }
    }

    private function partyOfLines(array $lines): ?Party
    {
        $parties = Party::whereIn('ledger_id', array_column($lines, 'ledger_id'))->get();

        return $parties->count() === 1 ? $parties->first() : null;
    }

    private function lockVoucher(int $id): JournalEntry
    {
        $entry = JournalEntry::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! in_array($entry->entry_type, VoucherCalculator::TYPES, true) || $entry->source_type !== null) {
            throw new ApiProblem('not_draft', "{$entry->voucher_number} was made by a document; change it through that document.");
        }

        return $entry;
    }
}
