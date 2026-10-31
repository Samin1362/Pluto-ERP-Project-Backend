<?php

namespace App\Models;

use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product and quantity moving; unit_cost is the source average frozen at dispatch, received_quantity set on receipt. */
class StockTransferLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => AsQuantity::class, 'unit_cost' => AsUnitCost::class, 'received_quantity' => AsQuantity::class];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
