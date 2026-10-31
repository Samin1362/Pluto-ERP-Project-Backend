<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock transfer (D11; 04 §6.5; 06 §11.4): draft → dispatched → received, or cancelled (a draft, or a dispatch taken
 * back). Entries exist only when the two warehouses use different inventory ledgers.
 */
class StockTransfer extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['dispatch_date' => 'date', 'received_date' => 'date'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
