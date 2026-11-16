<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A salary advance (PEP-523); changed only by StaffAdvanceService and the salary sheet approval. */
class StaffAdvance extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['given_on' => 'date', 'reversed_at' => 'datetime', 'lock_version' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
