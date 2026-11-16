<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One employee on a salary sheet (PEP-521); belongs to its run (no company_id of its own). */
class PayrollLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['days_in_month' => 'integer', 'days_paid' => 'integer'];
}
