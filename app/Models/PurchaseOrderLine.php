<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ordered line (cost in the PO currency) with its product snapshot; received_quantity grows as linked bills post. */
class PurchaseOrderLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => AsQuantity::class, 'unit_cost' => AsUnitCost::class, 'discount_pct' => 'decimal:4', 'discount_amount' => AsMoney::class,
        'tax_rate' => 'decimal:4', 'tax_amount' => AsMoney::class, 'net_amount' => AsMoney::class, 'line_total' => AsMoney::class,
        'received_quantity' => AsQuantity::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
