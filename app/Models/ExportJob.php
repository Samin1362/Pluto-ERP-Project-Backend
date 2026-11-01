<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/**
 * An asynchronous export (05 ExportJob; NFR-006; backlog P14.1-BE-05). `parameters` holds what to run (source,
 * format, filters, period, reports), the requester's session (the job runs as them) and, once done, the file name.
 * file_path is relative to the private disk; the file is removed 24 hours after the job finishes.
 */
class ExportJob extends Model
{
    use BelongsToCompany, TracksActor;

    public const TTL_HOURS = 24;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['parameters' => 'array', 'finished_at' => 'datetime'];
}
