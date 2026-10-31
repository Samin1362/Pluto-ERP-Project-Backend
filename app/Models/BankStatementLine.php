<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One statement line: signed amount (+ money in, − money out) and how it was handled in a reconciliation. */
class BankStatementLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['txn_date' => 'date', 'amount' => AsMoney::class, 'matched_at' => 'datetime'];
}
