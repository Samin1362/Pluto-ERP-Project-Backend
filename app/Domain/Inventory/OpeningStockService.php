<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Opening stock of a new product (06 §11.2; BR-028; backlog P11.1-BE-05): an `opening` movement into the warehouse
 * dated the current financial year's start, journalled Dr warehouse inventory / Cr opening balance adjustment (3301) as
 * an opening_balance entry pointing at the product. Used by product creation (D2) and the product import (D1). Runs
 * inside the caller's transaction.
 */
final class OpeningStockService
{
    public function __construct(private readonly StockService $stock, private readonly PostingService $posting, private readonly AccountMapper $mapper) {}

    public function post(Product $product, Warehouse $warehouse, Quantity $qty, UnitCost $cost): void
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $fy = FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first()
            ?? throw new ApiProblem('period_locked', "No financial year covers {$today}.");
        $date = $fy->start_date->toDateString();
        $value = Money::of($qty->toBigDecimal()->multipliedBy($cost->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
        $after = $this->stock->stockIn($product, $warehouse, $qty, $value, $product, $date, 'opening');
        $b = (new JournalBuilder)->debit($warehouse->inventory_ledger_id, $value->plus($after->trueUp), ['warehouse_id' => $warehouse->id])
            ->credit($this->mapper->ledgerFor('opening_balance_adjustment'), $value)->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
        if ($b->totalDebit()->isPositive()) {
            $entry = $this->posting->persist($b, $product, 'opening_balance', $date, null, "Opening stock of {$product->name} in {$warehouse->name}", $product->code,
                $warehouse->branch_id);
            $this->stock->linkJournal($product, $entry->id);
        }
    }
}
