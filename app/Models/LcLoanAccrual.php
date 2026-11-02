<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Month-end interest accrued on a loan (06 §12.12). */
class LcLoanAccrual extends Model
{
    use BelongsToCompany, TracksActor;

    protected $table = 'lc_loan_accruals';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['amount' => AsMoney::class, 'accrual_date' => 'date', 'interest_from' => 'date'];
}
