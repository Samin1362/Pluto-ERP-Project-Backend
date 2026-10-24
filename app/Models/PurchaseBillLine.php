<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bill line with its product snapshot (code, name, unit as on the bill) and calculated amounts. No company_id of its
 * own: it is always reached through its bill. debit_ledger_id is where the line posts (06 §8): the warehouse's
 * inventory ledger for stock, otherwise the expense ledger chosen at save.
 */
class PurchaseBillLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => AsQuantity::class, 'unit_cost' => AsUnitCost::class, 'discount_pct' => 'decimal:4', 'discount_amount' => AsMoney::class,
        'tax_rate' => 'decimal:4', 'tax_amount' => AsMoney::class, 'net_amount' => AsMoney::class, 'line_total' => AsMoney::class,
        'base_net_amount' => AsMoney::class, 'landed_cost_amount' => AsMoney::class, 'returned_quantity' => AsQuantity::class,
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
