<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Import credit lines a bank grants: LC limit, loan (PAD/LTR) limit and default loan terms (06 §12.11). */
class BankFacility extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $table = 'bank_facilities';

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['lc_limit' => AsMoney::class, 'loan_limit' => AsMoney::class, 'is_active' => 'boolean'];
}
