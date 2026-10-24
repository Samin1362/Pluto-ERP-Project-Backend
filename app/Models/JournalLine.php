<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsRate;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or credit of a journal entry (BR-002: exactly one side > 0). entry_date and is_posted are copied from
 * the entry so ledgers and reports read posted lines only (BR-005) without a join.
 */
class JournalLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'debit' => AsMoney::class, 'credit' => AsMoney::class, 'foreign_amount' => AsMoney::class, 'exchange_rate' => AsRate::class,
        'entry_date' => 'date', 'is_posted' => 'boolean',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
