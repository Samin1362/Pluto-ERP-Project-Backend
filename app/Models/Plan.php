<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** A Pluto plan the software owner sells (PEP-498). Platform-wide: not a company's data. */
class Plan extends Model
{
    use HasLockVersion, TracksActor;

    protected $guarded = ['id', 'lock_version'];

    protected $casts = ['modules' => 'array', 'is_active' => 'boolean', 'max_users' => 'integer', 'max_branches' => 'integer'];
}
