<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsRate;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier's bill (F2–F4, 04 §6.8): draft → posted → cancelled (06 §2). Its number (BILL-YYYY-NNNN) is reserved at
 * first save (BR-006); totals are stored from DocumentCalculator; posting links journal_entry_id (P4.3).
 */
class PurchaseBill extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'party_snapshot' => 'array', 'bill_date' => 'date', 'due_date' => 'date', 'exchange_rate' => AsRate::class,
        'subtotal' => AsMoney::class, 'discount_total' => AsMoney::class, 'tax_total' => AsMoney::class, 'rounding_adjustment' => AsMoney::class,
        'total' => AsMoney::class, 'total_foreign' => AsMoney::class, 'amount_settled' => AsMoney::class, 'balance_due' => AsMoney::class,
        'cancelled_at' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('line_no');
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
