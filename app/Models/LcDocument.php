<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Required document of one LC and its status (M4 Documents tab, M6 matrix); scans are attachments. */
class LcDocument extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['is_required' => 'boolean', 'due_date' => 'date', 'received_date' => 'date', 'copies' => 'integer'];
}
