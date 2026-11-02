<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** PAD/LTR loan made by the bank to pay an LC supplier (06 §12.12). */
class LcLoan extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $table = 'lc_loans';

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['principal' => AsMoney::class, 'start_date' => 'date', 'due_date' => 'date', 'interest_from' => 'date', 'converted_on' => 'date'];
}
