<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One employee's share of a salary payment (PEP-522). */
class PayrollPaymentLine extends Model
{
    protected $guarded = ['id'];
}
