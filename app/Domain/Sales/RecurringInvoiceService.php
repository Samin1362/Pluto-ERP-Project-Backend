<?php

namespace App\Domain\Sales;

use App\Domain\Shared\AuditLogger;
use App\Models\RecurringInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Recurring sales invoices (PEP-517): "Repeat this invoice" keeps the invoice's customer, warehouse, branch, terms,
 * notes and lines; the daily run (06:00 Dhaka, with recurring expenses) writes a draft invoice dated on each run date
 * (prices as saved; due date the same number of days after it as the original), never posted. Months step without
 * overflow (31 Jan → 28 Feb). A run whose draft cannot be written (locked period, item deleted) is skipped and reported.
 */
class RecurringInvoiceService
{
    public const FIELDS = ['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on', 'is_active'];

    private const LINE_FIELDS = ['product_id', 'unit_id', 'description', 'quantity', 'unit_price', 'discount_pct', 'tax_rule_id'];

    public function __construct(private readonly SalesInvoiceService $invoices, private readonly AuditLogger $audit) {}

    public function repeat(SalesInvoice $inv, array $in): RecurringInvoice
    {
        if ($inv->status === 'cancelled') {
            throw new ApiProblem('validation_failed', 'A cancelled invoice cannot be repeated.', errors: ['invoice' => ['A cancelled invoice cannot be repeated']]);
        }
        $this->assertEnds($in['ends_type'], $in['ends_after_count'] ?? null, $in['ends_on'] ?? null);
        $date = CarbonImmutable::parse($inv->invoice_date)->toDateString();
        if ($in['next_run_on'] <= $date) {
            throw new ApiProblem('validation_failed', errors: ['next_run_on' => ['The next one must come after this invoice\'s date']]);
        }
        $inv->loadMissing('lines');
        $payload = array_filter(['party_id' => (int) $inv->party_id, 'warehouse_id' => $inv->warehouse_id ? (int) $inv->warehouse_id : null,
            'branch_id' => $inv->branch_id ? (int) $inv->branch_id : null, 'payment_term_id' => $inv->payment_term_id ? (int) $inv->payment_term_id : null,
            'customer_po_ref' => $inv->customer_po_ref, 'notes' => $inv->notes, 'internal_note' => $inv->internal_note], fn ($v) => $v !== null && $v !== '');
        $payload['due_days'] = (int) CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($inv->due_date));
        $payload['lines'] = $inv->lines->sortBy('line_no')->map(function ($l) {
            $line = [];
            foreach (self::LINE_FIELDS as $k) {
                if ($l->{$k} !== null && $l->{$k} !== '') {
                    $line[$k] = str_ends_with($k, '_id') ? (int) $l->{$k} : (string) $l->{$k};
                }
            }

            return $line;
        })->values()->all();

        return DB::transaction(function () use ($inv, $in, $payload) {
            $t = RecurringInvoice::create(['source_invoice_id' => $inv->id, 'party_id' => $inv->party_id, 'frequency' => $in['frequency'], 'next_run_on' => $in['next_run_on'],
                'ends_type' => $in['ends_type'], 'ends_after_count' => $in['ends_type'] === 'after_count' ? $in['ends_after_count'] : null,
                'ends_on' => $in['ends_type'] === 'on_date' ? $in['ends_on'] : null, 'occurrences_done' => 1, 'payload' => $payload,
                'last_invoice_id' => $inv->id, 'is_active' => true]);
            $this->audit->record('created', $t, null, ['from_invoice' => $inv->number, 'frequency' => $t->frequency, 'next_run_on' => $in['next_run_on']], type: 'recurring_invoices');

            return $t->refresh(); // lock_version as stored
        });
    }

    public function update(RecurringInvoice $t, array $in, int $expectedVersion): RecurringInvoice
    {
        return DB::transaction(function () use ($t, $in, $expectedVersion) {
            $t = RecurringInvoice::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => $t->lock_version]);
            }
            $old = $t->only(self::FIELDS);
            $t->fill(array_intersect_key($in, array_flip(self::FIELDS)));
            if ($t->ends_type !== 'after_count') {
                $t->ends_after_count = null;
            }
            if ($t->ends_type !== 'on_date') {
                $t->ends_on = null;
            }
            $this->assertEnds($t->ends_type, $t->ends_after_count, $t->ends_on?->toDateString());
            if ($t->ends_type === 'on_date' && $t->ends_on && $t->ends_on->toDateString() < $t->next_run_on->toDateString()) {
                $t->is_active = false; // ended: nothing more is due
            }
            $t->save();
            $this->audit->record('updated', $t, $old, $t->only(self::FIELDS), type: 'recurring_invoices');

            return $t;
        });
    }

    /**
     * Drafts for every repeating invoice of the current company due today or earlier (one per missed date).
     *
     * @param  array<int, string>  $skipped  id => why, filled in
     */
    public function run(CarbonImmutable $today, array &$skipped = []): int
    {
        $made = 0;
        foreach (RecurringInvoice::where('is_active', true)->whereDate('next_run_on', '<=', $today->toDateString())->orderBy('id')->pluck('id') as $id) {
            try {
                $made += $this->runOne($id, $today);
            } catch (ApiProblem $e) {
                $skipped[$id] = $e->getMessage() ?: $e->errorCode;
            }
        }

        return $made;
    }

    private function runOne(int $id, CarbonImmutable $today): int
    {
        return DB::transaction(function () use ($id, $today) {
            $t = RecurringInvoice::whereKey($id)->lockForUpdate()->first();
            $n = 0;
            while ($t->is_active && $t->next_run_on->toDateString() <= $today->toDateString()) {
                if ($this->ended($t, CarbonImmutable::parse($t->next_run_on))) {
                    $t->is_active = false;
                    break;
                }
                if ($t->created_by && ($user = User::find($t->created_by))) {
                    Auth::setUser($user); // the draft is the template owner's, for the activity trail
                }
                $on = CarbonImmutable::parse($t->next_run_on);
                $body = $t->payload;
                $dueDays = (int) ($body['due_days'] ?? 0);
                unset($body['due_days']);
                $inv = $this->invoices->createDraft($body + ['invoice_date' => $on->toDateString(), 'due_date' => $on->addDays($dueDays)->toDateString()]);
                $t->forceFill(['last_invoice_id' => $inv->id, 'occurrences_done' => $t->occurrences_done + 1, 'next_run_on' => self::step($on, $t->frequency)->toDateString()]);
                $n++;
                if ($t->ends_type === 'after_count' && $t->occurrences_done >= (int) $t->ends_after_count) {
                    $t->is_active = false;
                }
            }
            $t->save();

            return $n;
        });
    }

    public static function step(CarbonImmutable $d, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $d->addWeek(),
            'quarterly' => $d->addMonthsNoOverflow(3),
            'yearly' => $d->addYearsNoOverflow(1),
            default => $d->addMonthNoOverflow(),
        };
    }

    /** RecurringInvoice (05 schema). */
    public static function resource(RecurringInvoice $t): array
    {
        $t->loadMissing(['party:id,code,name', 'sourceInvoice:id,number', 'lastInvoice:id,number']);

        return ['id' => $t->id, 'party' => ['id' => $t->party->id, 'code' => $t->party->code, 'name' => $t->party->name],
            'source_invoice_number' => $t->sourceInvoice?->number, 'frequency' => $t->frequency, 'next_run_on' => $t->next_run_on->toDateString(),
            'ends_type' => $t->ends_type, 'ends_after_count' => $t->ends_after_count, 'ends_on' => $t->ends_on?->toDateString(),
            'occurrences_done' => $t->occurrences_done, 'is_active' => $t->is_active, 'lines_count' => count($t->payload['lines'] ?? []),
            'last_invoice_id' => $t->last_invoice_id, 'last_invoice_number' => $t->lastInvoice?->number, 'version' => $t->lock_version];
    }

    private function ended(RecurringInvoice $t, CarbonImmutable $date): bool
    {
        return ($t->ends_type === 'on_date' && $t->ends_on && $date->gt($t->ends_on))
            || ($t->ends_type === 'after_count' && $t->occurrences_done >= (int) $t->ends_after_count);
    }

    private function assertEnds(string $type, ?int $count, ?string $on): void
    {
        if ($type === 'after_count' && (! $count || $count < 1)) {
            throw new ApiProblem('validation_failed', errors: ['ends_after_count' => ['Say after how many times it ends']]);
        }
        if ($type === 'on_date' && ! $on) {
            throw new ApiProblem('validation_failed', errors: ['ends_on' => ['Choose the date it ends']]);
        }
    }
}
