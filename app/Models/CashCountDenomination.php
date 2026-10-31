<?php

namespace App\Models;

use App\Casts\AsMoney;
use Illuminate\Database\Eloquent\Model;

/** One note or coin value counted and how many (value = denomination × count). */
class CashCountDenomination extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['denomination' => AsMoney::class, 'value' => AsMoney::class];
}
