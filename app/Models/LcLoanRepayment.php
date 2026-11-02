<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Repayment of a PAD/LTR loan: principal with the interest due to the date (06 §12.12). */
class LcLoanRepayment extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $table = 'lc_loan_repayments';

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['principal_amount' => AsMoney::class, 'interest_amount' => AsMoney::class, 'accrued_cleared' => AsMoney::class, 'repayment_date' => 'date', 'interest_from' => 'date'];
}
