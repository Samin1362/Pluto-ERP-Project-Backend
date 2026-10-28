<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsRate;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order (F8, F11; 04 §6.8): draft → sent → partly_received → received → closed, or cancelled (06 §2). Never
 * posts (BR-056); bills made from it (purchase_order_id) move its lines' received_quantity when they post or cancel.
 * A foreign order keeps its planning exchange_rate; total is in BDT and total_foreign in the order's currency.
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'party_snapshot' => 'array', 'order_date' => 'date', 'expected_date' => 'date', 'sent_at' => 'datetime', 'exchange_rate' => AsRate::class,
        'subtotal' => AsMoney::class, 'discount_total' => AsMoney::class, 'tax_total' => AsMoney::class, 'rounding_adjustment' => AsMoney::class,
        'total' => AsMoney::class, 'total_foreign' => AsMoney::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class)->orderBy('id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function currencyCode(): string
    {
        return $this->currency_code ?: 'BDT';
    }
}
