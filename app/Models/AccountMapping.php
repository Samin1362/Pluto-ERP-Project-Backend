<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Posting role -> ledger or group (06 §3). Replaces hard-coded account ids. */
class AccountMapping extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];
}
