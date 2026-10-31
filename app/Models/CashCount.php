<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A count of a cash account (G2 cash count; 04 §6.4; BR-058): the notes and coins counted, the book balance then and
 * the difference (counted − book). A difference becomes a journal waiting for approval (adjustment_entry_id).
 */
class CashCount extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['counted_at' => 'datetime', 'book_balance' => AsMoney::class, 'counted_total' => AsMoney::class, 'difference' => AsMoney::class];

    public function denominations(): HasMany
    {
        return $this->hasMany(CashCountDenomination::class)->orderByDesc('denomination');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'adjustment_entry_id');
    }
}
