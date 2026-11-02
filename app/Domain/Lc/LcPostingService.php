<?php

namespace App\Domain\Lc;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Models\BillAllocation;
use App\Models\JournalEntry;
use App\Models\LandedCostAllocation;
use App\Models\LcCharge;
use App\Models\LcPayment;
use App\Models\Ledger;
use App\Models\LetterOfCredit;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What an LC does to the books (06 §12.2–§12.9; DEC-10 confirmed 1 Nov 2026; backlog P16.1-BE-05..09). Every line on
 * 1403 (lc_margin), 1404 (goods_in_transit) and 2302 (lc_acceptance) carries the LC's id, so the tagged balances are
 * the LC's own and the cost summary agrees with the ledgers.
 *  - Charges post when recorded: margin deposit Dr 1403 (never landed cost); capitalised charges Dr 1404; others Dr
 *    bank charges; a recoverable import tax Dr its tax rule's ledger (owner decision). Cr the money account when paid,
 *    else the payee supplier or accrued expenses; paying later moves it to the money account.
 *  - Payments: amount_bdt = round(foreign × bank rate, 2) (BR-042); cumulative foreign ≤ amount × (1 + tolerance).
 *    Sight: Dr supplier (an advance until the import bill) / Cr 1403 margin applied / Cr bank, or Cr 2303 when the bank
 *    finances it as a PAD/LTR loan (§12.12, LcFinanceService). Usance acceptance:
 *    Dr supplier / Cr 2302; settlement at maturity: Dr 2302 carrying / Cr 1403, bank; difference to 6603.
 *  - Settling an import bill by an LC payment (BR-043): per allocation fx = foreign × (payment rate − bill rate); the
 *    allocation keeps foreign_amount and fx_amount, and the difference posts Dr 6603 / Cr supplier (loss) or the reverse.
 *  - Landed cost (BR-045): capitalised charges spread over the bill lines by value or quantity, the last line takes the
 *    remainder; the share of stock still on hand goes to inventory (landed_cost movement), the sold share to COGS.
 *  - Close (BR-046): checks, goods in transit expensed with an override reason, margin released.
 */
final class LcPostingService
{
    private const BANK_FEES = ['opening_commission', 'amendment_fee', 'swift_postage', 'stamp_documentation', 'acceptance_commission', 'discrepancy_fee'];

    public function __construct(private readonly PostingService $posting, private readonly AccountMapper $mapper, private readonly PeriodGuard $periods,
        private readonly StockService $stock, private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- read helpers

    /** Foreign amount paid to the beneficiary (sight payments and usance acceptances, posted). */
    public static function paidForeign(int $lcId): Money
    {
        return Money::of((string) DB::table('lc_payments')->where('letter_of_credit_id', $lcId)->where('status', 'posted')
            ->whereIn('payment_kind', ['sight_payment', 'usance_acceptance'])->sum('amount_foreign'));
    }

    /** Default rate of an import bill: the LC's payments weighted by foreign amount, else the planning rate (06 §12.5). */
    public static function billRate(LetterOfCredit $lc): string
    {
        $p = DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->whereIn('payment_kind', ['sight_payment', 'usance_acceptance'])
            ->selectRaw('COALESCE(SUM(amount_foreign), 0) f, COALESCE(SUM(amount_bdt), 0) b')->first();

        return BigDecimal::of((string) $p->f)->isPositive()
            ? (string) BigDecimal::of((string) $p->b)->dividedBy((string) $p->f, 6, RoundingMode::HalfUp)
            : (string) $lc->planning_rate;
    }

    /** Balance (Dr − Cr) of a mapped ledger's lines tagged with the LC. */
    public function tagged(int $lcId, string $key): Money
    {
        return Money::of((string) DB::table('journal_lines')->where('letter_of_credit_id', $lcId)->where('ledger_id', $this->mapper->ledgerFor($key)->id)
            ->where('is_posted', 1)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b'));
    }

    public static function charge(LcCharge $c, string $lcNumber): array
    {
        return ['id' => $c->id, 'letter_of_credit_id' => $c->letter_of_credit_id, 'lc_number' => $lcNumber, 'charge_type' => $c->charge_type,
            'charge_date' => $c->charge_date?->toDateString(), 'amount' => (string) $c->amount, 'status' => $c->status, 'paid_to' => $c->paid_to,
            'include_in_landed_cost' => (bool) $c->include_in_landed_cost, 'is_refundable' => (bool) $c->is_refundable,
            'allocated' => $c->landed_cost_allocation_id !== null, 'journal_entry_id' => $c->journal_entry_id, 'reference' => $c->reference,
            'tax_rule_id' => $c->tax_rule_id, 'posting_status' => $c->posting_status, 'version' => (int) $c->lock_version];
    }

    public static function payment(LcPayment $p): array
    {
        $fx = $p->payment_kind === 'usance_settlement'
            ? self::fxOnEntry($p->journal_entry_id)
            : Money::of((string) DB::table('bill_allocations as a')->join('journal_lines as l', 'l.id', '=', 'a.journal_line_id')
                ->where('l.journal_entry_id', $p->journal_entry_id)->whereNull('a.reversed_at')->sum('a.fx_amount'));

        return ['id' => $p->id, 'payment_kind' => $p->payment_kind, 'payment_date' => $p->payment_date?->toDateString(), 'amount_foreign' => (string) $p->amount_foreign,
            'bank_rate' => (string) $p->bank_rate, 'amount_bdt' => (string) $p->amount_bdt, 'margin_applied' => (string) $p->margin_applied,
            'fx_difference' => (string) $fx, 'journal_entry_id' => $p->journal_entry_id, 'status' => $p->status,
            'financed_by' => (string) ($p->financed_by ?? 'own'), 'lc_loan_id' => DB::table('lc_loans')->where('lc_payment_id', $p->id)->where('status', '!=', 'reversed')->value('id')];
    }

    private static function fxOnEntry(?int $entryId): Money
    {
        if (! $entryId) {
            return Money::zero();
        }
        $fx = app(AccountMapper::class)->ledgerFor('fx_gain_loss')->id;

        return Money::of((string) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('ledger_id', $fx)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b'));
    }

    /** M4 money position: margin, charges, goods in transit, payments, acceptances, FX. */
    public static function position(LetterOfCredit $lc): array
    {
        $self = app(self::class);
        $charges = DB::table('lc_charges')->where('letter_of_credit_id', $lc->id)->where('posting_status', 'posted');
        $paid = DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted');

        return [
            'margin_held' => (string) $self->tagged($lc->id, 'lc_margin'),
            'goods_in_transit' => (string) $self->tagged($lc->id, 'goods_in_transit'),
            'acceptance_outstanding' => (string) $self->tagged($lc->id, 'lc_acceptance')->negated(),
            'charges_total' => (string) Money::of((string) (clone $charges)->where('charge_type', '!=', 'margin_deposit')->sum('amount')),
            'charges_unpaid' => (string) Money::of((string) (clone $charges)->where('status', 'unpaid')->sum('amount')),
            'paid_foreign' => (string) self::paidForeign($lc->id), 'paid_bdt' => (string) Money::of((string) (clone $paid)->whereIn('payment_kind', ['sight_payment', 'usance_settlement'])->sum('amount_bdt')),
            'fx_total' => (string) Money::of((string) DB::table('journal_lines')->where('letter_of_credit_id', $lc->id)->where('ledger_id', $self->mapper->ledgerFor('fx_gain_loss')->id)
                ->where('is_posted', 1)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b')),
            'landed_cost_allocated' => (string) Money::of((string) DB::table('landed_cost_allocations')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->sum('total_amount')),
        ];
    }

    private function lockLc(int $id, array $notIn = ['closed', 'cancelled']): LetterOfCredit
    {
        $lc = LetterOfCredit::whereKey($id)->lockForUpdate()->firstOrFail();
        if (in_array($lc->status, $notIn, true)) {
            throw new ApiProblem('invalid_transition', "{$lc->lc_number} is {$lc->status}".($lc->status === 'closed' ? '; reopen it first.' : '.'));
        }

        return $lc;
    }

    private function accountLedger(int $moneyAccountId, string $field): int
    {
        $a = MoneyAccount::where('is_active', true)->find($moneyAccountId) ?? throw new ApiProblem('validation_failed', errors: [$field => ['Choose an active money account']]);

        return (int) $a->ledger_id;
    }

    // ---------------------------------------------------------------- charges (06 §12.2–12.3)

    public function addCharge(array $in): LcCharge
    {
        return DB::transaction(function () use ($in) {
            $lc = $this->lockLc((int) $in['letter_of_credit_id']);
            $amount = Money::of($in['amount']);
            $margin = $in['charge_type'] === 'margin_deposit';
            $rule = ! $margin && ! empty($in['tax_rule_id']) ? TaxRule::findOrFail($in['tax_rule_id']) : null;
            $capitalise = ! $margin && ! $rule && ($in['include_in_landed_cost'] ?? true);
            $paid = ($in['status'] ?? 'unpaid') === 'paid';
            if ($paid && empty($in['paid_from_account_id'])) {
                throw new ApiProblem('validation_failed', errors: ['paid_from_account_id' => ['Choose the account it was paid from']]);
            }
            if ($margin && ! $paid) {
                throw new ApiProblem('validation_failed', errors: ['status' => ['A margin deposit is paid from an account']]);
            }
            $payee = ! $paid && ! empty($in['payee_party_id']) ? Party::findOrFail($in['payee_party_id']) : null;
            if ($payee && $payee->party_type !== 'supplier') {
                throw new ApiProblem('validation_failed', errors: ['payee_party_id' => ['Choose a supplier (C&F agent, insurer)']]);
            }
            $c = new LcCharge;
            $c->forceFill(['letter_of_credit_id' => $lc->id, 'charge_type' => $in['charge_type'], 'charge_date' => $in['charge_date'], 'amount' => (string) $amount,
                'paid_to' => $in['paid_to'] ?? $payee?->name, 'payee_party_id' => $payee?->id, 'status' => $paid ? 'paid' : 'unpaid', 'posting_status' => 'posted',
                'paid_from_account_id' => $paid ? $in['paid_from_account_id'] : null, 'include_in_landed_cost' => $capitalise, 'is_refundable' => $margin,
                'reference' => $in['reference'] ?? null, 'lc_shipment_id' => $in['lc_shipment_id'] ?? null, 'lc_amendment_id' => $in['lc_amendment_id'] ?? null,
                'tax_rule_id' => $rule?->id])->save();

            $tag = ['letter_of_credit_id' => $lc->id];
            $b = new JournalBuilder;
            match (true) {
                $margin => $b->debit($this->mapper->ledgerFor('lc_margin'), $amount, $tag),
                $rule !== null => $b->debit($rule->ledger_id, $amount, $tag + ['tax_rule_id' => $rule->id]),
                $capitalise => $b->debit($this->mapper->ledgerFor('goods_in_transit'), $amount, $tag),
                default => $b->debit($this->mapper->ledgerFor('bank_charges'), $amount, $tag),
            };
            $b->credit($this->creditLedger($c), $amount, $tag + ['against_reference' => $lc->lc_number]);
            $entry = $this->posting->persist($b, $c, 'lc_charge', $in['charge_date'], null, $lc->lc_number.' · '.str_replace('_', ' ', $c->charge_type).($c->paid_to ? " · {$c->paid_to}" : ''),
                $in['reference'] ?? $lc->lc_number, $lc->branch_id);
            $c->forceFill(['journal_entry_id' => $entry->id])->save();
            $this->audit->record('created', $c, null, ['charge_type' => $c->charge_type, 'amount' => (string) $amount, 'lc' => $lc->lc_number], type: 'lc_charges');

            return $c->refresh();
        });
    }

    /** Where an unpaid charge waits (payee supplier or accrued expenses), or the money account it was paid from. */
    private function creditLedger(LcCharge $c): int
    {
        if ($c->status === 'paid' && $c->paid_from_account_id) {
            return $this->accountLedger((int) $c->paid_from_account_id, 'paid_from_account_id');
        }

        return $this->payableLedger($c);
    }

    private function payableLedger(LcCharge $c): int
    {
        if ($c->payee_party_id) {
            return (int) Party::withTrashed()->whereKey($c->payee_party_id)->value('ledger_id');
        }

        return (int) (Ledger::where('code', '2202')->value('id') ?? $this->mapper->ledgerFor('suspense')->id);
    }

    public function payCharge(int $id, array $in): LcCharge
    {
        return DB::transaction(function () use ($id, $in) {
            $c = LcCharge::whereKey($id)->lockForUpdate()->firstOrFail();
            $lc = $this->lockLc($c->letter_of_credit_id);
            if ($c->posting_status !== 'posted' || $c->status === 'paid') {
                throw new ApiProblem('invalid_transition', 'This charge is already paid or was reversed.');
            }
            $tag = ['letter_of_credit_id' => $lc->id];
            $b = (new JournalBuilder)->debit($this->payableLedger($c), $c->amount, $tag + ['against_reference' => $lc->lc_number])
                ->credit($this->accountLedger((int) $in['paid_from_account_id'], 'paid_from_account_id'), $c->amount, $tag);
            $this->posting->persist($b, $c, 'lc_charge', $in['date'], null, "Paid: {$lc->lc_number} · ".str_replace('_', ' ', $c->charge_type), $in['reference'] ?? $lc->lc_number, $lc->branch_id);
            $c->forceFill(['status' => 'paid', 'paid_from_account_id' => $in['paid_from_account_id'], 'reference' => $in['reference'] ?? $c->reference])->save();
            $this->audit->record('paid', $c, ['status' => 'unpaid'], ['status' => 'paid', 'date' => $in['date']], type: 'lc_charges');

            return $c->refresh();
        });
    }

    public function reverseCharge(int $id, string $reason): LcCharge
    {
        return DB::transaction(function () use ($id, $reason) {
            $c = LcCharge::whereKey($id)->lockForUpdate()->firstOrFail();
            $lc = $this->lockLc($c->letter_of_credit_id);
            if ($c->posting_status !== 'posted') {
                throw new ApiProblem('already_reversed', 'This charge is already reversed.');
            }
            if ($c->landed_cost_allocation_id && DB::table('landed_cost_allocations')->where('id', $c->landed_cost_allocation_id)->where('status', 'posted')->exists()) {
                throw new ApiProblem('charge_allocated', 'This charge is in a posted landed-cost allocation; reverse the allocation first.');
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            foreach (JournalEntry::where('source_type', 'lc_charges')->where('source_id', $c->id)->where('status', 'posted')->orderBy('id')->get() as $e) {
                $this->mirror($e, $date, "Reversal of {$lc->lc_number} charge: {$reason}");
            }
            $c->forceFill(['posting_status' => 'reversed'])->save();
            $this->audit->record('reversed', $c, ['posting_status' => 'posted'], ['posting_status' => 'reversed', 'reason' => $reason], type: 'lc_charges');

            return $c->refresh();
        });
    }

    /** A mirror entry of `$e` dated `$date` (every tag kept, so tagged balances net to zero); `$e` becomes reversed. */
    public function mirror(JournalEntry $e, string $date, string $narration): JournalEntry
    {
        $e = JournalEntry::with('lines')->whereKey($e->id)->lockForUpdate()->firstOrFail();
        $b = self::mirrorBuilder($e);
        $date = max($date, $e->entry_date->toDateString());
        $reversal = $this->posting->persist($b, null, 'reversal', $date, null, $narration, $e->voucher_number, $e->branch_id, $e->id);
        $e->forceFill(['status' => 'reversed'])->save();

        return $reversal;
    }

    /** The lines of `$e` with debits and credits swapped, every tag kept. */
    public static function mirrorBuilder(JournalEntry $e): JournalBuilder
    {
        $e->loadMissing('lines');
        $b = new JournalBuilder;
        foreach ($e->lines as $l) {
            $meta = array_filter(['letter_of_credit_id' => $l->letter_of_credit_id, 'warehouse_id' => $l->warehouse_id, 'tax_rule_id' => $l->tax_rule_id,
                'against_reference' => $l->against_reference, 'foreign_currency_code' => $l->foreign_currency_code, 'exchange_rate' => $l->exchange_rate !== null ? (string) $l->exchange_rate : null,
                'foreign_amount' => $l->foreign_amount !== null ? (string) $l->foreign_amount : null]);
            if (Money::of((string) $l->debit)->isPositive()) {
                $b->credit($l->ledger_id, Money::of((string) $l->debit), $meta);
            }
            if (Money::of((string) $l->credit)->isPositive()) {
                $b->debit($l->ledger_id, Money::of((string) $l->credit), $meta);
            }
        }

        return $b;
    }

    // ---------------------------------------------------------------- payments (06 §12.4, §12.6)

    public function pay(int $lcId, array $in): LcPayment
    {
        return DB::transaction(function () use ($lcId, $in) {
            $lc = $this->lockLc($lcId, ['draft', 'awaiting_bank', 'closed', 'cancelled']);
            $kind = $in['payment_kind'] ?? ($lc->tenor === 'usance' ? 'usance_acceptance' : 'sight_payment');
            if ($lc->tenor === 'sight' && $kind !== 'sight_payment') {
                throw new ApiProblem('validation_failed', errors: ['payment_kind' => ['A sight LC is paid with sight payments']]);
            }
            if ($lc->tenor === 'usance' && $kind === 'sight_payment') {
                throw new ApiProblem('validation_failed', errors: ['payment_kind' => ['A usance LC is accepted, then settled at maturity']]);
            }
            $foreign = Money::of($in['amount_foreign']);
            $rate = Rate::of($in['bank_rate']);
            $bdt = Money::of($foreign->toBigDecimal()->multipliedBy($rate->toBigDecimal())->toScale(2, RoundingMode::HalfUp)); // BR-042
            $margin = Money::of($in['margin_applied'] ?? '0');
            if ($kind !== 'usance_settlement') {
                $limit = $lc->amount->toBigDecimal()->multipliedBy(BigDecimal::of((string) $lc->tolerance_pct)->plus(100))->dividedBy(100, 2, RoundingMode::HalfUp);
                if (self::paidForeign($lc->id)->plus($foreign)->isGreaterThan(Money::of($limit))) {
                    throw new ApiProblem('exceeds_tolerance', "Payments would exceed the LC amount with its tolerance ({$lc->currency_code} {$limit}).",
                        errors: ['amount_foreign' => ["At most {$lc->currency_code} ".Money::of($limit)->minus(self::paidForeign($lc->id)).' more can be paid']]);
                }
            } else {
                $accepted = Money::of((string) DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('payment_kind', 'usance_acceptance')->sum('amount_foreign'));
                $settled = Money::of((string) DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('payment_kind', 'usance_settlement')->sum('amount_foreign'));
                if ($foreign->isGreaterThan($accepted->minus($settled))) {
                    throw new ApiProblem('validation_failed', errors: ['amount_foreign' => ["Only {$lc->currency_code} ".$accepted->minus($settled).' is accepted and not yet settled']]);
                }
            }
            if ($kind === 'usance_acceptance' && $margin->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['margin_applied' => ['Margin is applied when the acceptance is settled']]);
            }
            if ($margin->isGreaterThan($this->tagged($lc->id, 'lc_margin')) || $margin->isGreaterThan($bdt)) {
                throw new ApiProblem('margin_exceeds_deposit', 'Only ৳ '.$this->tagged($lc->id, 'lc_margin').' margin is held for this LC.', errors: ['margin_applied' => ['More than the margin held or the payment']]);
            }
            $financedBy = $in['financed_by'] ?? 'own';
            if ($financedBy !== 'own' && $kind === 'usance_acceptance') {
                throw new ApiProblem('validation_failed', errors: ['financed_by' => ['An acceptance is not paid yet; finance the settlement at maturity']]);
            }
            if ($financedBy !== 'own' && ! $bdt->minus($margin)->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['financed_by' => ['The margin covers this payment; nothing is left to finance']]);
            }
            $party = Party::withTrashed()->findOrFail($lc->party_id);
            $account = $this->accountLedger((int) $in['money_account_id'], 'money_account_id');
            $p = new LcPayment;
            $p->forceFill(['letter_of_credit_id' => $lc->id, 'lc_shipment_id' => $in['lc_shipment_id'] ?? null, 'purchase_bill_id' => $in['purchase_bill_id'] ?? null,
                'payment_kind' => $kind, 'payment_date' => $in['payment_date'], 'description' => $in['description'] ?? null, 'amount_foreign' => (string) $foreign,
                'bank_rate' => (string) $rate, 'amount_bdt' => (string) $bdt, 'money_account_id' => $in['money_account_id'], 'margin_applied' => (string) $margin,
                'bank_reference' => $in['bank_reference'] ?? null, 'financed_by' => $financedBy, 'status' => 'draft'])->save();

            $tag = ['letter_of_credit_id' => $lc->id];
            $foreignMeta = ['foreign_currency_code' => $lc->currency_code, 'foreign_amount' => $foreign, 'exchange_rate' => (string) $rate, 'against_reference' => $lc->lc_number];
            $b = new JournalBuilder;
            if ($kind === 'usance_settlement') {
                $carrying = $this->acceptanceCarrying($lc, $foreign);
                $b->debit($this->mapper->ledgerFor('lc_acceptance'), $carrying, $tag + $foreignMeta);
                $fx = $bdt->minus($carrying);
                if ($fx->isPositive()) {
                    $b->debit($this->mapper->ledgerFor('fx_gain_loss'), $fx, $tag);
                } elseif ($fx->isNegative()) {
                    $b->credit($this->mapper->ledgerFor('fx_gain_loss'), $fx->negated(), $tag);
                }
            } else {
                $b->debit($party->ledger_id, $bdt, $tag + $foreignMeta);
            }
            if ($kind === 'usance_acceptance') {
                $b->credit($this->mapper->ledgerFor('lc_acceptance'), $bdt, $tag + $foreignMeta);
            } else {
                if ($margin->isPositive()) {
                    $b->credit($this->mapper->ledgerFor('lc_margin'), $margin, $tag);
                }
                if ($bdt->minus($margin)->isPositive()) { // 06 §12.12: a financed payment is the bank's loan, not the company's money
                    $b->credit($financedBy === 'own' ? $account : $this->mapper->ledgerFor('lc_loan')->id, $bdt->minus($margin), $tag);
                }
            }
            $label = ['sight_payment' => 'Payment', 'usance_acceptance' => 'Acceptance', 'usance_settlement' => 'Settlement at maturity'][$kind];
            $entry = $this->posting->persist($b, $p, 'lc_payment', $in['payment_date'], null, "{$label} {$lc->lc_number}: {$lc->currency_code} {$foreign} at {$rate}",
                $in['bank_reference'] ?? $lc->lc_number, $lc->branch_id);
            $p->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id])->save();
            $this->audit->record('posted', $p, null, ['kind' => $kind, 'amount_foreign' => (string) $foreign, 'amount_bdt' => (string) $bdt, 'lc' => $lc->lc_number, 'financed_by' => $financedBy], type: 'lc_payments');
            if ($financedBy !== 'own') {
                app(LcFinanceService::class)->openLoan($lc, $p, $bdt->minus($margin), $in['loan']['loan_kind'] ?? $financedBy, $in['loan'] ?? []);
            }
            if ($kind !== 'usance_settlement') {
                $bills = PurchaseBill::where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('balance_due', '>', 0)
                    ->when($in['purchase_bill_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('bill_date')->orderBy('id')->get();
                foreach ($bills as $bill) {
                    $this->settleBill($bill);
                }
            }

            return $p->refresh();
        });
    }

    /** Taka the acceptances carry for `$foreign` (their weighted rate), to settle at maturity. */
    private function acceptanceCarrying(LetterOfCredit $lc, Money $foreign): Money
    {
        $a = DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('payment_kind', 'usance_acceptance')
            ->selectRaw('SUM(amount_foreign) f, SUM(amount_bdt) b')->first();
        $outstanding = $this->tagged($lc->id, 'lc_acceptance')->negated();
        $settledForeign = Money::of((string) DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('payment_kind', 'usance_settlement')->sum('amount_foreign'));
        if ($foreign->plus($settledForeign)->isEqualTo((string) $a->f)) {
            return $outstanding; // the last settlement clears what is left, so 2302 ends at zero
        }

        return Money::of($foreign->toBigDecimal()->multipliedBy((string) $a->b)->dividedBy((string) $a->f, 2, RoundingMode::HalfUp));
    }

    public function reversePayment(int $id, string $reason): LcPayment
    {
        return DB::transaction(function () use ($id, $reason) {
            $p = LcPayment::whereKey($id)->lockForUpdate()->firstOrFail();
            $lc = $this->lockLc($p->letter_of_credit_id);
            if ($p->status !== 'posted') {
                throw new ApiProblem('already_reversed', 'This payment is already reversed.');
            }
            if ($p->payment_kind === 'usance_acceptance' && DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->where('payment_kind', 'usance_settlement')->exists()) {
                throw new ApiProblem('invalid_transition', 'Reverse the settlement at maturity first.');
            }
            app(LcFinanceService::class)->reverseLoanOf($p, $reason); // refuses while the loan has repayments
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $lines = DB::table('journal_lines')->where('journal_entry_id', $p->journal_entry_id)->pluck('id');
            foreach (DB::table('bill_allocations')->whereIn('journal_line_id', $lines)->whereNull('reversed_at')->orderBy('id')->pluck('id') as $allocationId) {
                $this->unsettle((int) $allocationId, $date);
            }
            $this->mirror(JournalEntry::findOrFail($p->journal_entry_id), $date, "Reversal of the {$lc->lc_number} payment: {$reason}");
            $p->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $p, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason], type: 'lc_payments');

            return $p->refresh();
        });
    }

    // ---------------------------------------------------------------- import bill settlement (06 §12.5; BR-043)

    /**
     * Applies the LC's unapplied payments (sight, acceptance) to a posted import bill, oldest first, by foreign amount.
     * Each allocation reduces the bill at its own rate and the payment at its rate; the difference posts to 6603.
     */
    public function settleBill(PurchaseBill $bill): void
    {
        $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
        if (! $bill->letter_of_credit_id || $bill->status !== 'posted' || $bill->currency_code === 'BDT' || ! $bill->balance_due->isPositive()) {
            return;
        }
        $lc = LetterOfCredit::findOrFail($bill->letter_of_credit_id);
        $party = Party::withTrashed()->findOrFail($bill->party_id);
        $payments = LcPayment::where('letter_of_credit_id', $lc->id)->where('status', 'posted')->whereIn('payment_kind', ['sight_payment', 'usance_acceptance'])
            ->orderBy('payment_date')->orderBy('id')->get();
        foreach ($payments as $p) {
            $bill->refresh();
            if (! $bill->balance_due->isPositive()) {
                break;
            }
            $line = DB::table('journal_lines')->where('journal_entry_id', $p->journal_entry_id)->where('ledger_id', $party->ledger_id)->where('debit', '>', 0)->lockForUpdate()->first();
            if (! $line) {
                continue;
            }
            $lineAllocs = DB::table('bill_allocations')->where('journal_line_id', $line->id)->whereNull('reversed_at');
            $payForeignLeft = $p->amount_foreign->minus((string) (clone $lineAllocs)->sum('foreign_amount'));
            $payBdtLeft = Money::of((string) $line->debit)->minus((string) (clone $lineAllocs)->selectRaw('COALESCE(SUM(amount + fx_amount), 0) u')->value('u'));
            $billForeignLeft = Money::of($bill->total_foreign)->minus((string) DB::table('bill_allocations')->where('purchase_bill_id', $bill->id)->whereNull('reversed_at')->sum('foreign_amount'));
            $f = $payForeignLeft->isLessThan($billForeignLeft) ? $payForeignLeft : $billForeignLeft;
            if (! $f->isPositive() || ! $payBdtLeft->isPositive()) {
                continue;
            }
            $toBill = $f->isEqualTo($billForeignLeft) ? $bill->balance_due : Money::of($f->toBigDecimal()->multipliedBy($bill->exchange_rate->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
            $fromPayment = $f->isEqualTo($payForeignLeft) ? $payBdtLeft : Money::of($f->toBigDecimal()->multipliedBy($p->bank_rate->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
            if ($toBill->isGreaterThan($bill->balance_due)) {
                $toBill = $bill->balance_due;
            }
            $fx = $fromPayment->minus($toBill);
            $on = max($p->payment_date->toDateString(), $bill->bill_date->toDateString(), CarbonImmutable::now('Asia/Dhaka')->toDateString());
            $alloc = BillAllocation::create(['journal_line_id' => $line->id, 'purchase_bill_id' => $bill->id, 'amount' => (string) $toBill, 'allocated_on' => $on,
                'foreign_amount' => (string) $f, 'fx_amount' => (string) $fx, 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
            $settled = $bill->amount_settled->plus($toBill);
            $balance = $bill->total->minus($settled);
            $bill->forceFill(['amount_settled' => $settled, 'balance_due' => $balance, 'payment_status' => $balance->isPositive() ? 'part_paid' : 'paid'])->save();
            if (! $fx->isZero()) {
                $tag = ['letter_of_credit_id' => $lc->id];
                $b = new JournalBuilder;
                $fxLedger = $this->mapper->ledgerFor('fx_gain_loss');
                $fx->isPositive()
                    ? $b->debit($fxLedger, $fx, $tag)->credit($party->ledger_id, $fx, $tag + ['against_reference' => $bill->number])
                    : $b->debit($party->ledger_id, $fx->negated(), $tag + ['against_reference' => $bill->number])->credit($fxLedger, $fx->negated(), $tag);
                $this->posting->persist($b, $alloc, 'lc_payment', $on, null, 'Exchange '.($fx->isPositive() ? 'loss' : 'gain')." on {$bill->number}: {$lc->currency_code} {$f} paid at {$p->bank_rate}, billed at {$bill->exchange_rate}",
                    $lc->lc_number, $lc->branch_id);
            }
            $this->audit->record('allocated', $bill, null, ['allocation_id' => $alloc->id, 'lc' => $lc->lc_number, 'foreign' => (string) $f, 'amount' => (string) $toBill, 'fx' => (string) $fx]);
        }
    }

    /** Undoes one allocation (LC payment or bill reversed): the bill gets its balance back and the FX entry is mirrored. */
    public function unsettle(int $allocationId, string $date): void
    {
        $a = DB::table('bill_allocations')->where('id', $allocationId)->lockForUpdate()->first();
        if (! $a || $a->reversed_at !== null) {
            return;
        }
        DB::table('bill_allocations')->where('id', $a->id)->update(['reversed_at' => now(), 'updated_by' => auth()->id(), 'updated_at' => now()]);
        if ($a->purchase_bill_id) {
            $bill = PurchaseBill::whereKey($a->purchase_bill_id)->lockForUpdate()->first();
            if ($bill && $bill->status === 'posted') {
                $settled = $bill->amount_settled->minus($a->amount);
                $balance = $bill->total->minus($settled);
                $bill->forceFill(['amount_settled' => $settled, 'balance_due' => $balance, 'payment_status' => $settled->isZero() ? 'unpaid' : 'part_paid'])->save();
            }
        }
        $this->reverseFx($a->id, $date);
    }

    /** Mirrors the FX entry of an allocation, if it has one (also when a cancelled bill releases its allocations). */
    public function reverseFx(int $allocationId, string $date): void
    {
        $e = JournalEntry::where('source_type', 'bill_allocations')->where('source_id', $allocationId)->where('status', 'posted')->first();
        if ($e) {
            $this->mirror($e, $date, "Reversal of the exchange difference ({$e->narration})");
        }
    }

    // ---------------------------------------------------------------- landed cost (06 §12.7; BR-045)

    /** @return array LandedCostAllocation (05 schema) for the preview, plus the internals `_lines` / `_charges` to post. */
    public function landedCost(int $lcId, array $in, bool $preview = true): array
    {
        $lc = LetterOfCredit::findOrFail($lcId);
        $bill = PurchaseBill::with('lines')->where('letter_of_credit_id', $lc->id)->find($in['purchase_bill_id'])
            ?? throw new ApiProblem('validation_failed', errors: ['purchase_bill_id' => ["Choose a posted import bill of {$lc->lc_number}"]]);
        if ($bill->status !== 'posted') {
            throw new ApiProblem('validation_failed', errors: ['purchase_bill_id' => ["{$bill->number} is {$bill->status}; post it first"]]);
        }
        $charges = LcCharge::where('letter_of_credit_id', $lc->id)->where('posting_status', 'posted')->where('include_in_landed_cost', true)
            ->whereNull('landed_cost_allocation_id')->when($in['charge_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();
        $warnings = [];
        $total = Money::sum($charges->pluck('amount')->all());
        $products = Product::whereIn('id', $bill->lines->pluck('product_id'))->get()->keyBy('id');
        $lines = $bill->lines->filter(fn ($l) => $products[$l->product_id]->track_stock)->values();
        if ($lines->isEmpty()) {
            throw new ApiProblem('validation_failed', errors: ['purchase_bill_id' => ["{$bill->number} has no stock items to carry the cost"]]);
        }
        $method = $in['method'] ?? 'value';
        $basis = $lines->map(fn ($l) => $method === 'quantity' ? BigDecimal::of((string) $l->quantity) : $l->base_net_amount->toBigDecimal());
        $sum = $basis->reduce(fn ($a, $v) => $a->plus($v), BigDecimal::zero());
        if ($sum->isZero()) {
            throw new ApiProblem('validation_failed', errors: ['method' => ['The bill lines have no value to spread by; use quantity']]);
        }
        $out = [];
        $left = $total;
        foreach ($lines as $i => $l) {
            $last = $i === $lines->count() - 1;
            $alloc = $last ? $left : Money::of($total->toBigDecimal()->multipliedBy($basis[$i])->dividedBy($sum, 2, RoundingMode::HalfUp));
            $left = $left->minus($alloc);
            $received = Quantity::of((string) $l->quantity);
            $onHand = Quantity::of((string) (DB::table('stock_balances')->where('product_id', $l->product_id)->where('warehouse_id', $bill->warehouse_id)->value('qty_on_hand') ?? '0'));
            $kept = $onHand->isGreaterThan($received) ? $received : ($onHand->isNegative() ? Quantity::zero() : $onHand);
            $toInv = $kept->isEqualTo($received) ? $alloc : Money::of($alloc->toBigDecimal()->multipliedBy($kept->toBigDecimal())->dividedBy($received->toBigDecimal(), 2, RoundingMode::HalfUp));
            $out[] = ['purchase_bill_line_id' => $l->id, 'product_id' => $l->product_id, 'product_name' => $products[$l->product_id]->name, 'warehouse_id' => $bill->warehouse_id,
                'basis_value' => $method === 'quantity' ? (string) Money::of((string) $l->quantity) : (string) $l->base_net_amount, 'allocated_amount' => (string) $alloc,
                'qty_received' => (string) $received, 'qty_on_hand' => (string) $kept, 'amount_to_inventory' => (string) $toInv, 'amount_to_cogs' => (string) $alloc->minus($toInv),
                'per_unit' => (string) $alloc->toBigDecimal()->dividedBy($received->toBigDecimal(), 4, RoundingMode::HalfUp)];
        }
        if ($total->isZero()) {
            $warnings[] = ['code' => 'NOTHING_TO_ALLOCATE', 'message' => 'No capitalised charges are waiting to be allocated'];
        }
        $inv = Money::sum(array_map(fn ($r) => Money::of($r['amount_to_inventory']), $out));
        $cogs = $total->minus($inv);
        $wh = Warehouse::findOrFail($bill->warehouse_id);
        $journal = [];
        $ledger = fn (int $id, string $d, string $c) => ['ledger_id' => $id, 'ledger_code' => (string) Ledger::whereKey($id)->value('code'), 'ledger_name' => (string) Ledger::whereKey($id)->value('name'), 'debit' => $d, 'credit' => $c];
        if ($inv->isPositive()) {
            $journal[] = $ledger((int) $wh->inventory_ledger_id, (string) $inv, '0.00');
        }
        if ($cogs->isPositive()) {
            $journal[] = $ledger($this->mapper->ledgerFor('cogs')->id, (string) $cogs, '0.00');
        }
        if ($total->isPositive()) {
            $journal[] = $ledger($this->mapper->ledgerFor('goods_in_transit')->id, '0.00', (string) $total);
        }

        return ['id' => null, 'status' => 'preview', 'total_amount' => (string) $total, 'lines' => $out, 'journal' => $journal, 'warnings' => $warnings,
            '_charges' => $charges, '_bill' => $bill, '_method' => $method, '_inventory' => $inv, '_cogs' => $cogs, '_warehouse' => $wh];
    }

    public function allocate(int $lcId, array $in): LandedCostAllocation
    {
        return DB::transaction(function () use ($lcId, $in) {
            $lc = $this->lockLc($lcId);
            $p = $this->landedCost($lc->id, $in, false);
            $total = Money::of($p['total_amount']);
            if (! $total->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['charge_ids' => ['No capitalised charges are waiting to be allocated']]);
            }
            $a = new LandedCostAllocation;
            $a->forceFill(['letter_of_credit_id' => $lc->id, 'purchase_bill_id' => $p['_bill']->id, 'allocation_date' => $in['allocation_date'], 'method' => $p['_method'],
                'total_amount' => (string) $total, 'status' => 'draft'])->save();
            $tag = ['letter_of_credit_id' => $lc->id];
            $b = new JournalBuilder;
            foreach ($p['lines'] as $l) {
                DB::table('landed_cost_allocation_lines')->insert(['landed_cost_allocation_id' => $a->id] + array_intersect_key($l, array_flip(['purchase_bill_line_id', 'product_id',
                    'warehouse_id', 'basis_value', 'allocated_amount', 'qty_received', 'qty_on_hand', 'amount_to_inventory', 'amount_to_cogs'])));
                if (Money::of($l['amount_to_inventory'])->isPositive()) {
                    $this->stock->revalue(Product::findOrFail($l['product_id']), $p['_warehouse'], Money::of($l['amount_to_inventory']), $a, $in['allocation_date'], $l['purchase_bill_line_id']);
                }
            }
            if ($p['_inventory']->isPositive()) {
                $b->debit($p['_warehouse']->inventory_ledger_id, $p['_inventory'], $tag + ['warehouse_id' => $p['_warehouse']->id]);
            }
            if ($p['_cogs']->isPositive()) {
                $b->debit($this->mapper->ledgerFor('cogs'), $p['_cogs'], $tag);
            }
            $b->credit($this->mapper->ledgerFor('goods_in_transit'), $total, $tag);
            $entry = $this->posting->persist($b, $a, 'landed_cost', $in['allocation_date'], null, "Landed cost of {$lc->lc_number} to {$p['_bill']->number}", $lc->lc_number, $lc->branch_id);
            $this->stock->linkJournal($a, $entry->id);
            $a->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id])->save();
            LcCharge::whereIn('id', $p['_charges']->pluck('id'))->update(['landed_cost_allocation_id' => $a->id]);
            $this->audit->record('posted', $a, null, ['lc' => $lc->lc_number, 'bill' => $p['_bill']->number, 'total' => (string) $total], type: 'landed_cost_allocations');

            return $a->refresh();
        });
    }

    public function reverseAllocation(int $id, string $reason): LandedCostAllocation
    {
        return DB::transaction(function () use ($id, $reason) {
            $a = LandedCostAllocation::whereKey($id)->lockForUpdate()->firstOrFail();
            $lc = $this->lockLc($a->letter_of_credit_id);
            if ($a->status !== 'posted') {
                throw new ApiProblem('already_reversed', 'This allocation is already reversed.');
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            foreach (DB::table('landed_cost_allocation_lines')->where('landed_cost_allocation_id', $a->id)->get() as $l) {
                if (Money::of((string) $l->amount_to_inventory)->isPositive()) {
                    $value = Money::of((string) (DB::table('stock_balances')->where('product_id', $l->product_id)->where('warehouse_id', $l->warehouse_id)->value('total_value') ?? '0'));
                    if ($value->isLessThan((string) $l->amount_to_inventory)) {
                        throw new ApiProblem('invalid_transition', 'The goods carrying this cost have been sold; post a correcting journal instead.');
                    }
                    $this->stock->revalue(Product::findOrFail($l->product_id), Warehouse::findOrFail($l->warehouse_id), Money::of((string) $l->amount_to_inventory)->negated(), $a, $date,
                        (int) $l->purchase_bill_line_id);
                }
            }
            $this->mirror(JournalEntry::findOrFail($a->journal_entry_id), $date, "Reversal of the {$lc->lc_number} landed cost: {$reason}");
            LcCharge::where('landed_cost_allocation_id', $a->id)->update(['landed_cost_allocation_id' => null]);
            $a->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $a, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason], type: 'landed_cost_allocations');

            return $a->refresh();
        });
    }

    public static function allocation(LandedCostAllocation $a): array
    {
        $lines = DB::table('landed_cost_allocation_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.landed_cost_allocation_id', $a->id)->orderBy('l.id')
            ->get(['l.*', 'p.name as product_name'])->map(fn ($l) => ['purchase_bill_line_id' => $l->purchase_bill_line_id, 'product_id' => $l->product_id,
                'product_name' => $l->product_name, 'warehouse_id' => $l->warehouse_id, 'basis_value' => (string) $l->basis_value, 'allocated_amount' => (string) $l->allocated_amount,
                'qty_received' => (string) $l->qty_received, 'qty_on_hand' => (string) $l->qty_on_hand, 'amount_to_inventory' => (string) $l->amount_to_inventory,
                'amount_to_cogs' => (string) $l->amount_to_cogs])->all();
        $journal = $a->journal_entry_id ? DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $a->journal_entry_id)->orderBy('l.line_no')
            ->get(['l.ledger_id', 'g.code', 'g.name', 'l.debit', 'l.credit'])->map(fn ($l) => ['ledger_id' => $l->ledger_id, 'ledger_code' => $l->code, 'ledger_name' => $l->name,
                'debit' => (string) $l->debit, 'credit' => (string) $l->credit])->all() : [];

        return ['id' => $a->id, 'status' => $a->status, 'total_amount' => (string) $a->total_amount, 'lines' => $lines, 'journal' => $journal, 'warnings' => []];
    }

    // ---------------------------------------------------------------- close (06 §12.9; BR-046)

    /** @return list<array{key: string, ok: bool, message: string}> the closing checks */
    public function closeChecks(LetterOfCredit $lc): array
    {
        $openForeign = Money::zero();
        foreach (PurchaseBill::where('letter_of_credit_id', $lc->id)->where('status', 'posted')->get() as $bill) {
            $openForeign = $openForeign->plus(Money::of($bill->total_foreign ?? '0')->minus((string) DB::table('bill_allocations')->where('purchase_bill_id', $bill->id)->whereNull('reversed_at')->sum('foreign_amount')));
        }
        $billed = Money::of((string) PurchaseBill::where('letter_of_credit_id', $lc->id)->where('status', 'posted')->sum('total_foreign'));
        $unappliedPay = self::paidForeign($lc->id)->minus($billed->isLessThan(self::paidForeign($lc->id)) ? $billed : self::paidForeign($lc->id));
        $unpaid = DB::table('lc_charges')->where('letter_of_credit_id', $lc->id)->where('posting_status', 'posted')->where('status', 'unpaid')->count();
        $docs = DB::table('lc_documents')->where('letter_of_credit_id', $lc->id)->where('is_required', true)->whereIn('status', ['missing', 'discrepant', 'pending', 'not_yet_due'])->count();
        $git = $this->tagged($lc->id, 'goods_in_transit');
        $acc = $this->tagged($lc->id, 'lc_acceptance')->negated();

        return [
            ['key' => 'supplier_settled', 'ok' => $lc->status === 'expired' || ($openForeign->isZero() && $unappliedPay->isZero()),
                'message' => $openForeign->isZero() && $unappliedPay->isZero() ? 'Supplier settled in full' : "Supplier not settled: {$lc->currency_code} ".($openForeign->isPositive() ? $openForeign.' billed and unpaid' : $unappliedPay.' paid and not billed')],
            ['key' => 'charges_paid', 'ok' => $unpaid === 0, 'message' => $unpaid === 0 ? 'Every charge is paid' : "{$unpaid} charges not paid yet"],
            ['key' => 'documents', 'ok' => $docs === 0, 'message' => $docs === 0 ? 'Every required document is in' : "{$docs} required documents are missing, pending or discrepant"],
            ['key' => 'goods_in_transit', 'ok' => $git->isZero(), 'message' => $git->isZero() ? 'All charges allocated to the goods' : "৳ {$git} of charges not allocated yet"],
            ['key' => 'acceptance', 'ok' => $acc->isZero(), 'message' => $acc->isZero() ? 'No acceptance outstanding' : "৳ {$acc} accepted and not settled"],
        ];
    }

    public function close(int $lcId, array $in): LetterOfCredit
    {
        return DB::transaction(function () use ($lcId, $in) {
            $lc = LetterOfCredit::whereKey($lcId)->lockForUpdate()->firstOrFail();
            if (! in_array($lc->status, ['opened', 'partly_shipped', 'documents_presented', 'payment_due', 'expired'], true)) {
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} is {$lc->status}; it cannot be closed.");
            }
            $checks = $this->closeChecks($lc);
            $failed = array_values(array_filter($checks, fn ($c) => ! $c['ok']));
            $override = trim((string) ($in['override_reason'] ?? ''));
            if ($failed && $override === '') {
                throw new ApiProblem('lc_not_closable', implode('; ', array_column($failed, 'message')).'. Fix these or give an override reason.', extra: ['checks' => $checks]);
            }
            if (array_filter($failed, fn ($c) => in_array($c['key'], ['charges_paid', 'acceptance'], true))) {
                throw new ApiProblem('lc_not_closable', 'Unpaid charges and outstanding acceptances must be settled before closing, even with an override.', extra: ['checks' => $checks]);
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $tag = ['letter_of_credit_id' => $lc->id];
            $git = $this->tagged($lc->id, 'goods_in_transit');
            if ($git->isPositive()) { // expensed with the override reason (06 §12.9)
                $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('bank_charges'), $git, $tag)->credit($this->mapper->ledgerFor('goods_in_transit'), $git, $tag);
                $this->posting->persist($b, $lc, 'lc_charge', $date, null, "{$lc->lc_number} closed: unallocated charges expensed ({$override})", $lc->lc_number, $lc->branch_id);
            }
            $margin = $this->tagged($lc->id, 'lc_margin');
            if ($margin->isPositive()) { // BR-044: what is left of the margin goes back to the account
                $to = $in['release_margin_to_account_id'] ?? $lc->margin_account_id ?? $lc->issuing_bank_account_id;
                if (! $to) {
                    throw new ApiProblem('validation_failed', errors: ['release_margin_to_account_id' => ['Choose the account the margin goes back to']]);
                }
                $b = (new JournalBuilder)->debit($this->accountLedger((int) $to, 'release_margin_to_account_id'), $margin, $tag)->credit($this->mapper->ledgerFor('lc_margin'), $margin, $tag);
                $this->posting->persist($b, $lc, 'lc_charge', $date, null, "{$lc->lc_number} closed: margin released", $lc->lc_number, $lc->branch_id);
            }
            $old = ['status' => $lc->status];
            $lc->forceFill(['status' => 'closed', 'closed_on' => $date, 'closed_by' => auth()->id(), 'bank_action_note' => $override !== '' ? $override : $lc->bank_action_note])->save();
            $this->audit->record('closed', $lc, $old, ['status' => 'closed', 'override_reason' => $override ?: null, 'margin_released' => (string) $margin], type: 'letters_of_credit');

            return $lc->refresh();
        });
    }

    /** Cancelling or expiring an LC releases the margin it still holds (06 §12.2). */
    public function releaseMargin(LetterOfCredit $lc, string $why): void
    {
        $margin = $this->tagged($lc->id, 'lc_margin');
        $to = $lc->margin_account_id ?? $lc->issuing_bank_account_id;
        if (! $margin->isPositive() || ! $to) {
            return;
        }
        $tag = ['letter_of_credit_id' => $lc->id];
        $b = (new JournalBuilder)->debit($this->accountLedger((int) $to, 'margin_account_id'), $margin, $tag)->credit($this->mapper->ledgerFor('lc_margin'), $margin, $tag);
        $this->posting->persist($b, $lc, 'lc_charge', CarbonImmutable::now('Asia/Dhaka')->toDateString(), null, "{$lc->lc_number} {$why}: margin released", $lc->lc_number, $lc->branch_id);
    }
}
