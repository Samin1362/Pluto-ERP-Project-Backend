<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Quotation (E10, E13; 04 §6.7): draft → sent → accepted / declined / expired → converted (06 §2). Never posts (BR-056);
 * its QT number is reserved at first save (BR-006). Converting creates one draft invoice whose quotation_id points back.
 */
class Quotation extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'party_snapshot' => 'array', 'quotation_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime',
        'subtotal' => AsMoney::class, 'discount_total' => AsMoney::class, 'tax_total' => AsMoney::class, 'rounding_adjustment' => AsMoney::class,
        'total' => AsMoney::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The draft (or later) invoice made from this quotation. */
    public function invoice(): HasOne
    {
        return $this->hasOne(SalesInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
