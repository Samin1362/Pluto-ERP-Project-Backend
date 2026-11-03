<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra unit an item is bought or sold in: one of `unit_id` is `factor` of the item's base unit (products.unit_id),
 * e.g. 1 box = 12 pcs (BR-069, P10.7). Stock is always kept in the base unit.
 */
class ProductUnit extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['factor' => 'decimal:6'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
