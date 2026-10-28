<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Debit note (F7, F13; 04 §6.8): draft → posted → cancelled (06 §2, §10). A return's note is created posted and shares
 * the return's journal entry (R-04); a note for a price difference, short supply or quality claim posts its own. The apply
 * target and cancel details live in party_snapshot.note (no columns for them).
 */
class DebitNote extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['party_snapshot' => 'array', 'note_date' => 'date', 'posted_at' => 'datetime', 'amount' => AsMoney::class];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{apply_to_document_id?: ?int, cancellation_entry_id?: ?int, cancel_reason?: ?string} */
    public function meta(): array
    {
        return $this->party_snapshot['note'] ?? [];
    }
}
