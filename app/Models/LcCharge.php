<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bank charge or import expense of an LC; posts when recorded (06 §12.2–12.3). */
class LcCharge extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['amount' => AsMoney::class, 'charge_date' => 'date', 'include_in_landed_cost' => 'boolean', 'is_refundable' => 'boolean'];

    public function letterOfCredit(): BelongsTo
    {
        return $this->belongsTo(LetterOfCredit::class);
    }
}
