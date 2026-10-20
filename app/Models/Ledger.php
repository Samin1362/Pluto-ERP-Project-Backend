<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

class Ledger extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['track_bills' => 'boolean', 'show_in_cash_vouchers' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
}
