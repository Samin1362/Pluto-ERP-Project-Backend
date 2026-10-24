<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only stock ledger row (04 §6.5): one movement in or out of a warehouse, with the quantity and average cost
 * after it (BR-017). Written only by StockService; never updated (no updated_at).
 */
class StockMovement extends Model
{
    use BelongsToCompany, TracksActor;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'moved_on' => 'date', 'quantity_in' => AsQuantity::class, 'quantity_out' => AsQuantity::class, 'unit_cost' => AsUnitCost::class,
        'value_in' => AsMoney::class, 'value_out' => AsMoney::class, 'qty_balance_after' => AsQuantity::class, 'avg_cost_after' => AsUnitCost::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
