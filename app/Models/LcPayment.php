<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsRate;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Payment to the beneficiary at the bank rate: sight, usance acceptance or usance settlement (06 §12.4, §12.6). */
class LcPayment extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['amount_foreign' => AsMoney::class, 'bank_rate' => AsRate::class, 'amount_bdt' => AsMoney::class, 'margin_applied' => AsMoney::class, 'payment_date' => 'date'];

    public function letterOfCredit(): BelongsTo
    {
        return $this->belongsTo(LetterOfCredit::class);
    }
}
