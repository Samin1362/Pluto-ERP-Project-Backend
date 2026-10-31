<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credit note (E9, E15; 04 §6.7): draft → posted → cancelled (06 §2, §7). Posting credits the customer (Dr sales
 * returns); usage apply allocates it to an invoice, credit leaves it on the ledger, refund pays it out. The table has no
 * columns for the apply target or the refund details, so they live in party_snapshot.note {apply_to_document_id, refund}
 * and, once posted, refund_entry_id / cancellation_entry_id.
 */
class CreditNote extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['party_snapshot' => 'array', 'note_date' => 'date', 'posted_at' => 'datetime', 'amount' => AsMoney::class];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array{apply_to_document_id?: ?int, refund?: ?array, refund_entry_id?: ?int, cancellation_entry_id?: ?int, cancel_reason?: ?string} */
    public function meta(): array
    {
        return $this->party_snapshot['note'] ?? [];
    }
}
