<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Spreads an LC's capitalised charges over an import bill's lines (06 §12.7; BR-045). */
class LandedCostAllocation extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['total_amount' => AsMoney::class, 'allocation_date' => 'date'];
}
