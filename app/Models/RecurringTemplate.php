<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/**
 * A repeating income, expense or journal (I10; BR-048): payload is the validated entry body; every run on next_run_on
 * creates a draft for someone to confirm, never a posted entry.
 */
class RecurringTemplate extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['payload' => 'array', 'next_run_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean',
        'occurrences_done' => 'integer', 'ends_after_count' => 'integer', 'lock_version' => 'integer'];
}
