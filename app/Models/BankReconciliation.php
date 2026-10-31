<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reconciliation of one bank account to a statement date and balance (G10; BR-032). */
class BankReconciliation extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['statement_date' => 'date', 'statement_balance' => AsMoney::class, 'book_balance' => AsMoney::class, 'difference' => AsMoney::class,
        'finished_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('txn_date')->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }
}
