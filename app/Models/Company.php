<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasLockVersion, TracksActor;

    protected $guarded = ['id', 'lock_version'];
}
