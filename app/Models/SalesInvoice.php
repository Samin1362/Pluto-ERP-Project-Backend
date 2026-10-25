<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sales invoice (E2–E6, 04 §6.7): draft → posted → cancelled (06 §2). Its number (INV-YYYY-NNNN) is reserved at first
 * save (BR-006); totals are stored from DocumentCalculator; posting moves stock out and links journal_entry_id (06 §5).
 */
class SalesInvoice extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'party_snapshot' => 'array', 'invoice_date' => 'date', 'due_date' => 'date',
        'subtotal' => AsMoney::class, 'discount_total' => AsMoney::class, 'tax_total' => AsMoney::class, 'rounding_adjustment' => AsMoney::class,
        'total' => AsMoney::class, 'amount_settled' => AsMoney::class, 'balance_due' => AsMoney::class,
        'cancelled_at' => 'datetime', 'delivered_at' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
