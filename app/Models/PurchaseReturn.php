<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Purchase return (F6, F13; 04 §6.8): draft → posted → cancelled (06 §2, §10). Posting sends the goods back at the cost
 * they came in at and debits the supplier in one entry, which is also its debit note's entry (R-04). The table has no
 * columns for how that note is used or the cancel reason, so they live in party_snapshot (note.usage,
 * note.apply_to_document_id, cancel_reason).
 */
class PurchaseReturn extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['party_snapshot' => 'array', 'return_date' => 'date', 'posted_at' => 'datetime', 'total' => AsMoney::class];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('line_no');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function debitNote(): HasOne
    {
        return $this->hasOne(DebitNote::class)->latestOfMany();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
