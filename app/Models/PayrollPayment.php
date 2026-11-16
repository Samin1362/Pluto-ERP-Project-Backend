<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Salaries paid against an approved sheet from one money account (PEP-522); changed only by PayrollPaymentService. */
class PayrollPayment extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['paid_on' => 'date', 'reversed_at' => 'datetime', 'employees_count' => 'integer', 'lock_version' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollPaymentLine::class);
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
