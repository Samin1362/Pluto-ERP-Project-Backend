<?php

namespace App\Domain\Banking;

use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\VoucherService;
use App\Domain\Attachments\AttachmentService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\AccountGroup;
use App\Models\CompanyUser;
use App\Models\IeCategory;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\RecurringTemplate;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Income and expenses (I3, I6, I7, I10; backlog P13.1-BE-02..04; BR-054, BR-048, BR-007, BR-031, BR-004).
 *  - Categories: each has its own ledger — a new one under 61 Operating expenses or 42 Other income, or an existing
 *    ledger of the same nature that no other category uses — and an optional monthly budget with a warning level.
 *  - An entry is an expense (EXP-) or income (INC-) voucher posted at once through VoucherService, calculated like a
 *    payment / receipt: Dr the category ledger, Cr the money account (income the other way round). With a tax rule the
 *    amount is tax-inclusive and the tax part goes to the rule's ledger (input tax only when recoverable). Above the
 *    submitter's payment limit the expense waits for approval (202, BR-007). Pending uploads are linked to it.
 *  - Recurrence stores a template of the entry; run() makes a draft voucher on each due date (never posts, BR-048),
 *    advances next_run_on and ends the template after its count or end date.
 */
final class IncomeExpenseService
{
    /** Where a new category ledger goes: 61 Operating expenses, 42 Other income. */
    private const GROUP = ['expense' => '61', 'income' => '42'];

    private const STEP = ['weekly' => '1 week', 'monthly' => '1 month', 'quarterly' => '3 months', 'yearly' => '1 year'];

    public function __construct(
        private readonly LedgerService $ledgers,
        private readonly VoucherService $vouchers,
        private readonly AttachmentService $attachments,
        private readonly AuditLogger $audit,
    ) {}

    public function createCategory(array $in): IeCategory
    {
        return DB::transaction(function () use ($in) {
            $kind = $in['kind'];
            if (! empty($in['create_ledger']) || empty($in['ledger_id'])) {
                $group = AccountGroup::where('code', self::GROUP[$kind])->firstOrFail();
                $ledger = $this->ledgers->create(['account_group_id' => $group->id, 'name' => $in['name'], 'description' => $in['description'] ?? null]);
            } else {
                $ledger = $this->assertLedger((int) $in['ledger_id'], $kind);
            }
            $this->assertUniqueName($kind, $in['name']);
            $c = IeCategory::create(['kind' => $kind, 'name' => $in['name'], 'description' => $in['description'] ?? null, 'ledger_id' => $ledger->id,
                'monthly_budget' => isset($in['monthly_budget']) ? (string) Money::of($in['monthly_budget']) : null, 'warn_at_percent' => $in['warn_at_percent'] ?? '90']);
            $this->audit->record('created', $c, null, $c->only(['kind', 'name', 'ledger_id', 'monthly_budget', 'warn_at_percent']), type: 'ie_categories');

            return $c;
        });
    }

    /** Name, description, budget and warning; the kind and ledger stay (the ledger's entries are the category's history). */
    public function updateCategory(IeCategory $c, array $in): IeCategory
    {
        return DB::transaction(function () use ($c, $in) {
            $c = IeCategory::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($in['kind'] !== $c->kind) {
                throw new ApiProblem('validation_failed', errors: ['kind' => ['An income category cannot become an expense category, or the other way round']]);
            }
            if (isset($in['ledger_id']) && (int) $in['ledger_id'] !== (int) $c->ledger_id) {
                throw new ApiProblem('validation_failed', errors: ['ledger_id' => ['The ledger of a category cannot change']]);
            }
            if ($in['name'] !== $c->name) {
                $this->assertUniqueName($c->kind, $in['name'], $c->id);
            }
            $old = $c->only(['name', 'description', 'monthly_budget', 'warn_at_percent']);
            $c->fill(['name' => $in['name'], 'description' => array_key_exists('description', $in) ? $in['description'] : $c->description]);
            if (array_key_exists('monthly_budget', $in)) {
                $c->monthly_budget = $in['monthly_budget'] !== null ? (string) Money::of($in['monthly_budget']) : null;
            }
            if (isset($in['warn_at_percent'])) {
                $c->warn_at_percent = $in['warn_at_percent'];
            }
            $c->save();
            $this->audit->record('updated', $c, $old, $c->only(['name', 'description', 'monthly_budget', 'warn_at_percent']), type: 'ie_categories');

            return $c;
        });
    }

    /** @return array{0: JournalEntry, 1: bool} the voucher, and whether it went for approval */
    public function record(array $in, CompanyUser $membership): array
    {
        return DB::transaction(function () use ($in, $membership) {
            $category = IeCategory::findOrFail($in['category_id']);
            if ($category->kind !== $in['kind']) {
                throw new ApiProblem('validation_failed', errors: ['category_id' => ["{$category->name} is an {$category->kind} category"]]);
            }
            [$entry, $approval] = $this->vouchers->create($this->voucherInput($in, $category, 'post'), $membership);
            $this->attachments->linkPending(array_map('intval', $in['attachment_ids'] ?? []), $entry->id);
            if (! empty($in['recurrence'])) {
                $this->startRecurrence($in, $entry);
            }

            return [$entry, $approval];
        }, attempts: 2);
    }

    public function updateTemplate(RecurringTemplate $t, array $in, int $expectedVersion): RecurringTemplate
    {
        return DB::transaction(function () use ($t, $in, $expectedVersion) {
            $t = RecurringTemplate::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => $t->lock_version]);
            }
            $old = $t->only(['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on', 'is_active']);
            $t->fill(array_intersect_key($in, array_flip(['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on', 'is_active'])));
            $this->assertEnds($t->ends_type, $t->ends_after_count, $t->ends_on?->toDateString(), 'ends_');
            if ($t->ends_type === 'on_date' && $t->ends_on && $t->ends_on->toDateString() < $t->next_run_on->toDateString()) {
                $t->is_active = false; // ended: nothing more is due
            }
            $t->save();
            $this->audit->record('updated', $t, $old, $t->only(['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on', 'is_active']), type: 'recurring_templates');

            return $t;
        });
    }

    /**
     * The scheduler's run (06:00 Asia/Dhaka) for the current company: a draft for every template due today or earlier
     * (one per missed date), never posted (BR-048). A template whose draft cannot be written (say, its date is in a
     * locked period) is left as it is and reported in $skipped; the others still run.
     *
     * @param  array<int, string>  $skipped  template id => why, filled in
     * @return int drafts created
     */
    public function run(CarbonImmutable $today, array &$skipped = []): int
    {
        $made = 0;
        foreach (RecurringTemplate::where('is_active', true)->whereDate('next_run_on', '<=', $today->toDateString())->orderBy('id')->pluck('id') as $id) {
            try {
                $made += $this->runOne($id, $today);
            } catch (ApiProblem $e) {
                $skipped[$id] = $e->detail ?? $e->errorCode;
            }
        }

        return $made;
    }

    private function runOne(int $id, CarbonImmutable $today): int
    {
        return DB::transaction(function () use ($id, $today) {
            $t = RecurringTemplate::whereKey($id)->lockForUpdate()->first();
            $n = 0;
            while ($t->is_active && $t->next_run_on->toDateString() <= $today->toDateString()) {
                if ($this->ended($t, $t->next_run_on)) {
                    $t->is_active = false;
                    break;
                }
                if ($t->created_by && ($user = User::find($t->created_by))) {
                    Auth::setUser($user); // the draft is the template owner's, for the activity trail
                }
                $category = IeCategory::find($t->payload['category_id'] ?? 0);
                if (! $category) {
                    $t->is_active = false; // its category is gone; nothing sensible to draft
                    break;
                }
                [$entry] = $this->vouchers->create($this->voucherInput($t->payload + ['date' => $t->next_run_on->toDateString()], $category, 'draft'), null);
                $t->forceFill(['last_entry_id' => $entry->id, 'occurrences_done' => $t->occurrences_done + 1,
                    'next_run_on' => $t->next_run_on->add(self::STEP[$t->frequency])->toDateString()]);
                $n++;
                if ($t->ends_type === 'after_count' && $t->occurrences_done >= (int) $t->ends_after_count) {
                    $t->is_active = false;
                }
            }
            $t->save();

            return $n;
        });
    }

    /** The VoucherInput of an entry (or of a template's payload on a run date). */
    private function voucherInput(array $in, IeCategory $category, string $action): array
    {
        $amount = Money::of($in['amount']);
        $lines = [['ledger_id' => $category->ledger_id, 'amount' => (string) $amount, 'line_narration' => $in['payee_text'] ?? null]];
        if (! empty($in['tax_rule_id'])) {
            $lines = $this->withTax($lines, $amount, TaxRule::findOrFail($in['tax_rule_id']), $in['kind'], $in['payee_text'] ?? null, $category->ledger_id);
        }

        return ['entry_type' => $in['kind'], 'action' => $action, 'date' => $in['date'], 'reference' => $in['reference'] ?? null,
            'narration' => $in['notes'] ?? trim($category->name.(! empty($in['payee_text']) ? ' — '.$in['payee_text'] : '')),
            'confirm_backdated' => (bool) ($in['confirm_backdated'] ?? false), 'lines' => $lines,
            'payment' => ['money_account_id' => (int) $in['money_account_id'], 'method' => $in['method'], 'payee_text' => $in['payee_text'] ?? null,
                'transaction_reference' => $in['reference'] ?? null]];
    }

    /** Tax-inclusive amount: the tax part = amount × rate / (100 + rate), to the rule's ledger (input tax only when recoverable). */
    private function withTax(array $lines, Money $amount, TaxRule $rule, string $kind, ?string $payee, int $ledgerId): array
    {
        $side = $kind === 'expense' ? 'purchases' : 'sales';
        if (! $rule->is_active || ! in_array($rule->applies_to, [$side, 'both'], true)) {
            throw new ApiProblem('validation_failed', errors: ['tax_rule_id' => ["{$rule->name} does not apply to ".($kind === 'expense' ? 'purchases and expenses' : 'sales and income')]]);
        }
        if ($kind === 'expense' && ! $rule->is_recoverable) {
            return $lines; // non-recoverable input tax is part of the cost (R-14)
        }
        $rate = (string) $rule->rate;
        $tax = Money::of($amount->toBigDecimal()->multipliedBy($rate)->dividedBy(BigDecimal::of('100')->plus($rate), 2, RoundingMode::HALF_UP));
        if (! $tax->isPositive()) {
            return $lines;
        }

        return [['ledger_id' => $ledgerId, 'amount' => (string) $amount->minus($tax), 'line_narration' => $payee],
            ['ledger_id' => (int) $rule->ledger_id, 'amount' => (string) $tax, 'line_narration' => "{$rule->name} {$rate}%"]];
    }

    private function startRecurrence(array $in, JournalEntry $entry): void
    {
        $r = $in['recurrence'];
        $this->assertEnds($r['ends_type'], $r['ends_after_count'] ?? null, $r['ends_on'] ?? null, 'recurrence.ends_');
        if ($r['next_run_on'] <= $in['date']) {
            throw new ApiProblem('validation_failed', errors: ['recurrence.next_run_on' => ['The next one must come after this entry\'s date']]);
        }
        $payload = array_intersect_key($in, array_flip(['kind', 'amount', 'category_id', 'money_account_id', 'method', 'payee_text', 'reference', 'tax_rule_id', 'notes']));
        $t = RecurringTemplate::create(['kind' => $in['kind'], 'frequency' => $r['frequency'], 'next_run_on' => $r['next_run_on'], 'ends_type' => $r['ends_type'],
            'ends_after_count' => $r['ends_type'] === 'after_count' ? $r['ends_after_count'] : null, 'ends_on' => $r['ends_type'] === 'on_date' ? $r['ends_on'] : null,
            'occurrences_done' => 1, 'payload' => $payload, 'last_entry_id' => $entry->id, 'is_active' => true]);
        $this->audit->record('created', $t, null, ['kind' => $t->kind, 'frequency' => $t->frequency, 'next_run_on' => $r['next_run_on'], 'from_voucher' => $entry->voucher_number],
            type: 'recurring_templates');
    }

    private function ended(RecurringTemplate $t, CarbonImmutable|Carbon $date): bool
    {
        return ($t->ends_type === 'on_date' && $t->ends_on && $date->gt($t->ends_on))
            || ($t->ends_type === 'after_count' && $t->occurrences_done >= (int) $t->ends_after_count);
    }

    private function assertEnds(string $type, ?int $count, ?string $on, string $prefix): void
    {
        if ($type === 'after_count' && (! $count || $count < 1)) {
            throw new ApiProblem('validation_failed', errors: ["{$prefix}after_count" => ['Say after how many times it ends']]);
        }
        if ($type === 'on_date' && ! $on) {
            throw new ApiProblem('validation_failed', errors: ["{$prefix}on" => ['Choose the date it ends']]);
        }
    }

    private function assertLedger(int $id, string $kind): Ledger
    {
        $ledger = Ledger::find($id);
        if (! $ledger || AccountGroup::whereKey($ledger->account_group_id)->value('nature') !== $kind) {
            throw new ApiProblem('validation_failed', errors: ['ledger_id' => ["Choose an {$kind} ledger"]]);
        }
        if (IeCategory::where('ledger_id', $id)->exists()) {
            throw new ApiProblem('validation_failed', errors: ['ledger_id' => ["{$ledger->name} already belongs to another category"]]);
        }

        return $ledger;
    }

    private function assertUniqueName(string $kind, string $name, ?int $except = null): void
    {
        if (IeCategory::where('kind', $kind)->where('name', $name)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw new ApiProblem('validation_failed', errors: ['name' => ["There is already an {$kind} category called {$name}"]]);
        }
    }
}
