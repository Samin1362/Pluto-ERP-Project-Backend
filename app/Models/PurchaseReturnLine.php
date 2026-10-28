<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quantity of one bill line going back to the supplier, at the cost it came in at (BDT), and why. */
class PurchaseReturnLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => AsQuantity::class, 'unit_cost' => AsUnitCost::class, 'value' => AsMoney::class];

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseBillLine::class, 'purchase_bill_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
