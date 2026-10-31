<?php

namespace App\Domain\Banking;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Accounting\VoucherService;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\CashCount;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash counts (G2; 06 §13; BR-058, BR-003; R-19; TC-BNK-006, TC-BNK-010; backlog P12.1-BE-03).
 *
 * The notes and coins counted give the counted total; the book balance is the cash ledger's posted balance at that
 * moment; difference = counted − book. No difference: the count is simply recorded. A difference is never posted
 * directly (BR-058): a journal — shortage Dr cash short/over / Cr the cash account, excess the other way — is saved and
 * submitted for approval (banking.approve via the approval drawer); once approved it posts and the count is
 * `adjusted`. Reversing (banking.approve) mirrors a posted adjustment with a system journal dated today; a still
 * pending one is withdrawn instead (its approval request is cancelled).
 */
final class CashCountService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly ApprovalService $approvals,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{money_account_id: int, counted_at: string, denominations: list<array{denomination: string, count: int}>, note?: ?string}  $in */
    public function record(array $in, CompanyUser $membership): CashCount
    {
        return DB::transaction(function () use ($in, $membership) {
            $account = MoneyAccount::whereKey($in['money_account_id'])->lockForUpdate()->firstOrFail();
            if ($account->account_type !== 'cash') {
                throw new ApiProblem('validation_failed', errors: ['money_account_id' => ["{$account->name} is not a cash account; bank accounts are reconciled instead"]]);
            }
            if (! $account->is_active) {
                throw new ApiProblem('inactive_reference', errors: ['money_account_id' => ["{$account->name} is inactive"]]);
            }
            $rows = array_map(fn ($d) => ['denomination' => Money::of($d['denomination']), 'count' => (int) $d['count'],
                'value' => Money::of($d['denomination'])->multipliedBy((string) (int) $d['count'])], $in['denominations']);
            $counted = Money::sum(array_column($rows, 'value'));
            $book = MoneyAccountService::bookBalance((int) $account->ledger_id);
            $difference = $counted->minus($book);
            $countedAt = CarbonImmutable::parse($in['counted_at'])->setTimezone('Asia/Dhaka');

            $count = new CashCount(['money_account_id' => $account->id, 'counted_at' => $countedAt, 'counted_by' => $membership->user_id, 'book_balance' => $book,
                'counted_total' => $counted, 'difference' => $difference, 'note' => $in['note'] ?? null, 'status' => 'recorded']);
            $count->save();
            foreach ($rows as $r) {
                $count->denominations()->create(['denomination' => $r['denomination'], 'count' => $r['count'], 'value' => $r['value']]);
            }
            if (! $difference->isZero()) {
                $shortOver = $this->mapper->ledgerFor('cash_short_over')->id;
                $amount = (string) $difference->abs();
                [$short, $over] = $difference->isNegative() ? [$shortOver, $account->ledger_id] : [$account->ledger_id, $shortOver];
                [$entry] = $this->vouchers->create(['entry_type' => 'journal', 'action' => 'submit', 'date' => $countedAt->toDateString(),
                    'narration' => ($difference->isNegative() ? 'Cash short' : 'Cash over')." in {$account->name} at the count of {$countedAt->format('j M Y H:i')}",
                    'reference' => 'Cash count', 'lines' => [['ledger_id' => $short, 'debit' => $amount], ['ledger_id' => $over, 'credit' => $amount]]], $membership);
                $count->forceFill(['adjustment_entry_id' => $entry->id])->save();
            }
            $this->audit->record('created', $count, null, ['account' => $account->name, 'counted' => (string) $counted, 'book' => (string) $book,
                'difference' => (string) $difference, 'adjustment_entry_id' => $count->adjustment_entry_id]);

            return $count->refresh()->load(['denominations', 'counter', 'entry']);
        }, attempts: 2);
    }

    public function reverse(CashCount $count, string $reason): CashCount
    {
        return DB::transaction(function () use ($count, $reason) {
            $count = CashCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === 'reversed') {
                throw new ApiProblem('already_reversed', 'This cash count is already reversed.');
            }
            $was = $count->status;
            $entry = $count->adjustment_entry_id ? JournalEntry::with('lines')->whereKey($count->adjustment_entry_id)->lockForUpdate()->first() : null;
            $mirror = null;
            if ($entry && $entry->status === 'posted') {
                $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
                $b = new JournalBuilder;
                foreach ($entry->lines as $l) {
                    $b->debit($l->ledger_id, Money::of((string) $l->credit))->credit($l->ledger_id, Money::of((string) $l->debit));
                }
                $mirror = $this->posting->persist($b, $count, 'reversal', $date, null, "Reversal of the cash count adjustment {$entry->voucher_number} ({$reason})",
                    $entry->voucher_number, null, $entry->id);
                $entry->forceFill(['status' => 'reversed'])->save();
            } elseif ($entry && in_array($entry->status, ['submitted', 'draft', 'rejected'], true)) {
                $this->approvals->cancelPending($entry);
                $entry->forceFill(['status' => 'rejected'])->save(); // withdrawn: it never posted
            }
            $count->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $count, ['status' => $was], ['status' => 'reversed', 'reason' => $reason, 'reversal_entry_id' => $mirror?->id]);

            return $count->refresh()->load(['denominations', 'counter', 'entry']);
        }, attempts: 2);
    }
}
