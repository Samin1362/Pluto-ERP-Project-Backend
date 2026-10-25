<?php

namespace App\Domain\Sales;

use App\Domain\Documents\Calculation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;

/** A priced, checked sales invoice from SalesInvoicePreparer: what calculate shows and what a draft stores. */
final readonly class PreparedInvoice
{
    /**
     * @param  list<array{product: Product, input: array, stocked: bool, unit_price: Money, income_ledger_id: int, tax_rate: Rate, available: ?string}>  $lines
     * @param  list<array{code: string, message: string, line_no?: int}>  $warnings
     * @param  list<string>  $effects
     */
    public function __construct(
        public Party $party,
        public Warehouse $warehouse,
        public array $lines,
        public Calculation $calculation,
        public CarbonImmutable $invoiceDate,
        public CarbonImmutable $dueDate,
        public ?int $paymentTermId,
        public array $warnings,
        public array $effects,
        public Money $partyBalanceBefore,
    ) {}

    public function partyBalanceAfter(): Money
    {
        return $this->partyBalanceBefore->plus($this->calculation->total);
    }
}
