<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** A message from the software owner to every company (PEP-513). Platform-wide: not a company's data. */
class Announcement extends Model
{
    use HasLockVersion, TracksActor;

    protected $guarded = ['id', 'lock_version'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
