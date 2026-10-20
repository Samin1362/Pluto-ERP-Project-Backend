<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

class PartyBankAccount extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['is_default' => 'boolean'];
}
