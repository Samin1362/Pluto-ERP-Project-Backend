<?php

namespace App\Domain\Purchases;

use App\Domain\Documents\Calculation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;

/**
 * A bill input priced and checked against master data (PurchaseBillPreparer): what calculate shows and what a draft
 * stores. `lines` keep the input order (API index) with the resolved product, debit ledger and tax rate.
 */
final readonly class PreparedBill
{
    /**
     * @param  list<array{product: Product, input: array, stocked: bool, debit_ledger_id: int, tax_rate: Rate, available: ?string}>  $lines
     * @param  list<array{code: string, message: string, line_no?: int}>  $warnings
     * @param  list<string>  $effects
     */
    public function __construct(
        public Party $party,
        public Warehouse $warehouse,
        public array $lines,
        public Calculation $calculation,
        public string $currency,
        public Rate $exchangeRate,
        public CarbonImmutable $billDate,
        public CarbonImmutable $dueDate,
        public ?int $paymentTermId,
        public array $warnings,
        public array $effects,
        public Money $partyBalanceBefore,
    ) {}

    public function foreign(): bool
    {
        return $this->currency !== 'BDT';
    }
}
