<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sales invoice that repeats (PEP-517): payload is the draft invoice body without its dates; every run on next_run_on
 * creates a draft invoice for someone to check and post, never a posted one.
 */
class RecurringInvoice extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['payload' => 'array', 'next_run_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean',
        'occurrences_done' => 'integer', 'ends_after_count' => 'integer', 'lock_version' => 'integer'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'source_invoice_id');
    }

    public function lastInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'last_invoice_id');
    }
}
