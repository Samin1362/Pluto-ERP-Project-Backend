<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Customer or supplier with its own ledger (1:1). Master data: soft-deleted only when it has no history (BR-026). */
class Party extends Model
{
    use BelongsToCompany, HasLockVersion, SoftDeletes, TracksActor;

    protected $guarded = ['id', 'company_id', 'code', 'ledger_id', 'lock_version'];

    protected $casts = ['credit_limit' => 'decimal:2'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PartyGroup::class, 'party_group_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class);
    }
}
