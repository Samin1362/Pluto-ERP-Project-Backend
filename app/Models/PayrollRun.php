<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A salary sheet for one month (PEP-521); changed only by PayrollRunService. */
class PayrollRun extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['period_month' => 'date', 'pay_date' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        'employees_count' => 'integer', 'lock_version' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('employee_code');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
