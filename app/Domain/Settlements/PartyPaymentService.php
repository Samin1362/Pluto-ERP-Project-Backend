<?php

namespace App\Domain\Settlements;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Accounting\VoucherService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts (E7; 06 §6; BR-004, BR-020, BR-021, BR-053, BR-065; backlog P6.1-BE-03). One transaction:
 * RV-YYYY-NNNN entry Dr money account (amount − bank charge) · Dr bank_charges (charge) · Cr customer (amount), its
 * payment_details row (method, cheque, unapplied amount), then the allocations — the explicit list, or with
 * auto_allocate the open invoices oldest due first (AllocationPlanner) — each through AllocationService. Whatever is
 * not allocated stays on the customer ledger as an advance. Supplier payments (F5): pay(), a PV through VoucherService.
 */
final class PartyPaymentService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AllocationService $allocations,
        private readonly AllocationPlanner $planner,
        private readonly AuditLogger $audit,
        private readonly VoucherService $vouchers,
    ) {}

    public function receive(array $in): JournalEntry
    {
        return DB::transaction(function () use ($in) {
            $party = Party::findOrFail($in['party_id']);
            $account = MoneyAccount::with('ledger')->findOrFail($in['money_account_id']);
            $this->assertReferences($party, $account, $in);
            $amount = Money::of($in['amount']);
            $charge = Money::of($in['bank_charge'] ?? '0');
            if (! $charge->isLessThan($amount)) {
                throw new ApiProblem('validation_failed', errors: ['bank_charge' => ['The bank charge must be less than the amount received']]);
            }
            $date = CarbonImmutable::parse($in['date']);
            $period = $this->periods->assertOpen($date);
            $this->assertNotBackdated($date, (bool) ($in['confirm_backdated'] ?? false));

            $number = $this->numbers->next('receipt', $period->fiscal_year_id)['number'];
            $b = (new JournalBuilder)->debit($account->ledger_id, $amount->minus($charge));
            if ($charge->isPositive()) {
                $b->debit($this->mapper->ledgerFor('bank_charges'), $charge, ['line_narration' => 'Bank charge on '.$number]);
            }
            $b->credit($party->ledger_id, $amount);
            $narration = $in['narration'] ?? "Received from {$party->name}";
            $entry = $this->posting->persist($b, null, 'receipt', $date, $number, $narration, $in['transaction_reference'] ?? $in['cheque_number'] ?? null);
            DB::table('payment_details')->insert(['company_id' => $entry->company_id, 'journal_entry_id' => $entry->id, 'direction' => 'in', 'party_id' => $party->id,
                'money_account_id' => $account->id, 'method' => $in['method'], 'cheque_number' => $in['cheque_number'] ?? null, 'cheque_date' => $in['cheque_date'] ?? null,
                'drawn_on' => $in['drawn_on'] ?? null, 'transaction_reference' => $in['transaction_reference'] ?? null, 'amount' => (string) $amount,
                'bank_charge' => (string) $charge, 'unapplied_amount' => (string) $amount, 'created_by' => auth()->id(), 'updated_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now()]);

            $line = (int) $entry->lines->firstWhere('ledger_id', $party->ledger_id)->id;
            foreach ($this->plan($party, $amount, $in) as $a) {
                $this->allocations->allocate($line, 'sales_invoice', $a['id'], $a['amount'], $date->toDateString());
            }
            $this->audit->record('created', $entry, null, ['voucher_number' => $number, 'party_id' => $party->id, 'amount' => (string) $amount,
                'money_account_id' => $account->id, 'method' => $in['method']], type: 'journal_entries');

            return $entry->refresh()->load('lines');
        }, attempts: 2);
    }

    /**
     * Supplier payment (F5, Pur-DlgPay; 06 §9; BR-007, BR-020, BR-031, BR-065; backlog P8.1-BE-01): a PV through
     * VoucherService — Dr supplier (amount), Dr bank_charges (charge), Cr money account (amount + charge) — so the
     * funds check (overdraft limit), the back-date window and the role's payment limit apply as for any payment
     * voucher. Allocations (explicit, or auto oldest due first) settle the supplier's bills when it posts; above the
     * limit it is submitted for approval instead (`approval` true, 202) and posts when a purchases.approve holder agrees.
     *
     * @return array{0: JournalEntry, 1: bool} the voucher, and whether it went for approval
     */
    public function pay(array $in, CompanyUser $membership): array
    {
        return DB::transaction(function () use ($in, $membership) {
            $party = Party::findOrFail($in['party_id']);
            $account = MoneyAccount::findOrFail($in['money_account_id']);
            $errors = [];
            if ($party->status !== 'active') {
                $errors['party_id'] = ["{$party->name} is inactive"];
            }
            if (! $account->is_active || ! $account->use_for_payments) {
                $errors['money_account_id'] = [$account->is_active ? "{$account->name} is not used for payments" : "{$account->name} is inactive"];
            }
            if ($errors) {
                throw new ApiProblem('validation_failed', errors: $errors); // the supplier-payments contract declares no inactive_reference
            }
            if (isset($in['exchange_rate']) && ! Rate::of($in['exchange_rate'])->isEqualTo(1)) {
                throw new ApiProblem('validation_failed', errors: ['exchange_rate' => ['Payments are in taka; foreign-currency payments come with LC payments']]);
            }
            $amount = Money::of($in['amount']);
            $allocations = array_map(fn ($a) => ['document_id' => $a['id'], 'amount' => (string) $a['amount']], $this->plan($party, $amount, $in, 'purchase_bills', 'bill_date'));
            [$entry, $approval] = $this->vouchers->create([
                'entry_type' => 'payment', 'action' => 'post', 'date' => $in['date'], 'narration' => $in['narration'] ?? "Paid to {$party->name}",
                'reference' => $in['transaction_reference'] ?? $in['cheque_number'] ?? null, 'confirm_backdated' => (bool) ($in['confirm_backdated'] ?? false),
                'payment' => ['money_account_id' => $account->id, 'method' => $in['method'], 'party_id' => $party->id, 'cheque_number' => $in['cheque_number'] ?? null,
                    'cheque_date' => $in['cheque_date'] ?? null, 'drawn_on' => $in['drawn_on'] ?? null, 'transaction_reference' => $in['transaction_reference'] ?? null,
                    'bank_charge' => $in['bank_charge'] ?? '0'],
                'lines' => [['ledger_id' => $party->ledger_id, 'amount' => (string) $amount, 'allocations' => $allocations]],
            ], $membership);

            return [$entry->refresh()->load('lines'), $approval];
        }, attempts: 2);
    }

    /**
     * Explicit allocations (in document id order, the lock order of 05 §4; their sum ≤ the amount) or, with
     * auto_allocate, the planner's oldest-due-first plan over the party's open invoices (or bills).
     *
     * @return list<array{id: int, amount: Money}>
     */
    private function plan(Party $party, Money $amount, array $in, string $table = 'sales_invoices', string $dateColumn = 'invoice_date'): array
    {
        if (! empty($in['allocations'])) {
            $rows = collect($in['allocations'])->map(fn ($a) => ['id' => (int) $a['document_id'], 'amount' => Money::of($a['amount'])])->sortBy('id')->values()->all();
            $sum = Money::sum(array_column($rows, 'amount'));
            if ($sum->isGreaterThan($amount)) {
                throw new ApiProblem('allocation_exceeds_balance', "The allocations add up to {$sum}, more than the {$amount} received.",
                    errors: ['allocations' => ["The allocations add up to more than the amount received ({$sum})"]], extra: ['available' => (string) $amount]);
            }

            return $rows;
        }
        if (! ($in['auto_allocate'] ?? false)) {
            return [];
        }
        $open = DB::table($table)->where('party_id', $party->id)->where('status', 'posted')->where('balance_due', '>', 0)
            ->get(['id', 'due_date', $dateColumn, 'balance_due'])
            ->map(fn ($d) => ['id' => $d->id, 'due_date' => (string) $d->due_date, 'date' => (string) $d->{$dateColumn}, 'balance' => Money::of($d->balance_due)])->all();
        $plan = $this->planner->plan($amount, $open)['allocations'];
        usort($plan, fn ($a, $b) => $a['id'] <=> $b['id']); // lock order

        return $plan;
    }

    private function assertReferences(Party $party, MoneyAccount $account, array $in): void
    {
        $errors = [];
        if ($party->status !== 'active') {
            $errors['party_id'] = ["{$party->name} is inactive"];
        }
        if (! $account->is_active || ! $account->use_for_receipts) {
            $errors['money_account_id'] = [$account->is_active ? "{$account->name} is not used for receipts" : "{$account->name} is inactive"];
        }
        if ($errors) {
            throw new ApiProblem('inactive_reference', errors: $errors);
        }
        if (isset($in['exchange_rate']) && ! Rate::of($in['exchange_rate'])->isEqualTo(1)) {
            throw new ApiProblem('validation_failed', errors: ['exchange_rate' => ['Receipts are in taka; foreign-currency receipts come with LC payments']]);
        }
    }

    /** BR-053: a receipt dated before the back-date window needs explicit confirmation. */
    private function assertNotBackdated(CarbonImmutable $date, bool $confirmed): void
    {
        $window = (int) (CompanySetting::value('backdate_warning_days') ?? 0);
        if ($window > 0 && ! $confirmed && $date->lt(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window))) {
            throw new ApiProblem('backdated_confirmation_required', "The receipt is dated {$date->format('j M Y')}, more than {$window} days ago; confirm to post it.",
                errors: ['confirm_backdated' => ['Confirm the back-dated receipt']]);
        }
    }
}
