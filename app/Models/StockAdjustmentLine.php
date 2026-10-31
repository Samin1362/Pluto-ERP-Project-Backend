<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product's count: system and counted quantity, the signed difference (counted − system) and its signed value. */
class StockAdjustmentLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['system_qty' => AsQuantity::class, 'counted_qty' => AsQuantity::class, 'difference_qty' => AsQuantity::class,
        'unit_cost' => AsUnitCost::class, 'value' => AsMoney::class];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
