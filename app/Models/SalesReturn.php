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
 * Sales return (E8, E15; 04 §6.7): draft → posted → cancelled (06 §2, §7). Posting brings restocked goods back at the
 * invoice line's cost and creates a draft credit note (its financial part). The table has no columns for how that note
 * is used, so the return keeps them in party_snapshot.note {usage, apply_to_document_id} until posting.
 */
class SalesReturn extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['party_snapshot' => 'array', 'return_date' => 'date', 'posted_at' => 'datetime', 'total' => AsMoney::class];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_no');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class)->latestOfMany();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
