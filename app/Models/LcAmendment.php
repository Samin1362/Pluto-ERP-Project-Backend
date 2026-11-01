<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Amendment to LC terms: requested > issued > accepted (changes applied) or refused (BR-041). */
class LcAmendment extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['bank_fee' => AsMoney::class, 'requested_on' => 'date', 'issued_on' => 'date', 'accepted_on' => 'date'];
}
