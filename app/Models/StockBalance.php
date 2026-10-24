<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** On-hand quantity and moving-average cost per product and warehouse (BR-017). Written only by posting services (P4). */
class StockBalance extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'qty_on_hand' => 'decimal:3', 'qty_in_transit_out' => 'decimal:3', 'total_value' => 'decimal:2', 'avg_unit_cost' => 'decimal:4',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
