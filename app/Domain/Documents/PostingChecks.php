<?php

namespace App\Domain\Documents;

use App\Domain\Accounting\PeriodGuard;
use App\Domain\Purchases\PreparedBill;
use App\Domain\Sales\PreparedInvoice;
use App\Models\CompanySetting;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonInterface;

/**
 * "Checks before posting" (E3/F3 aside; UI redesign A+, PEP-480): what posting will look at, each as passed, needs
 * attention (warn) or will refuse the post (block). Built from the calculation's own warnings and the same PeriodGuard
 * the posting service uses, so the list and the post never disagree. Nothing here changes data.
 *
 * @phpstan-type Check array{key: string, status: 'pass'|'warn'|'block', label: string, line_no?: int|null}
 */
final class PostingChecks
{
    public function __construct(private readonly PeriodGuard $periods) {}

    /** @return list<Check> */
    public function forInvoice(PreparedInvoice $inv): array
    {
        $stocked = array_filter($inv->lines, fn ($l) => $l['stocked']);

        return array_values(array_filter([
            $this->period($inv->invoiceDate),
            $stocked ? $this->fromWarnings('stock', $inv->warnings, ['LOW_STOCK'], 'Enough stock for every line', ! CompanySetting::value('allow_negative_stock')) : null,
            $this->fromWarnings('credit_limit', $inv->warnings, ['CREDIT_LIMIT_EXCEEDED'],
                $inv->party->credit_limit === null ? 'No credit limit set for this customer' : 'Within the credit limit'),
            $this->fromWarnings('party_overdue', $inv->warnings, ['PARTY_OVERDUE'], 'No overdue invoices for this customer'),
            $this->fromWarnings('backdated', $inv->warnings, ['BACKDATED'], 'Date needs no back-dating confirmation'),
        ]));
    }

    /** @return list<Check> */
    public function forBill(PreparedBill $b, bool $linked): array
    {
        return array_values(array_filter([
            $this->period($b->billDate),
            $linked ? $this->fromWarnings('order_quantity', $b->warnings, ['ORDER_QTY_EXCEEDED'], 'Quantities are within the order', true) : null,
            $this->fromWarnings('backdated', $b->warnings, ['BACKDATED'], 'Date needs no back-dating confirmation'),
        ]));
    }

    /** @return Check */
    private function period(CarbonInterface $date): array
    {
        try {
            $p = $this->periods->assertOpen($date);

            return ['key' => 'period_open', 'status' => 'pass', 'label' => "{$p->name} is open for posting", 'line_no' => null];
        } catch (ApiProblem $e) {
            return ['key' => 'period_open', 'status' => 'block', 'label' => $e->detail ?? 'The date is in a locked period', 'line_no' => null];
        }
    }

    /**
     * @param  list<array{code: string, message: string, line_no?: int|null}>  $warnings
     * @param  list<string>  $codes
     * @return Check
     */
    private function fromWarnings(string $key, array $warnings, array $codes, string $ok, bool $blocks = false): array
    {
        $hits = array_values(array_filter($warnings, fn ($w) => in_array($w['code'], $codes, true)));
        if (! $hits) {
            return ['key' => $key, 'status' => 'pass', 'label' => $ok, 'line_no' => null];
        }

        return ['key' => $key, 'status' => $blocks ? 'block' : 'warn', 'label' => implode(' ', array_column($hits, 'message')), 'line_no' => $hits[0]['line_no'] ?? null];
    }
}
