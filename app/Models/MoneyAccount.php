<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash, bank, mobile wallet, card or cash-credit account (G3/G4, 04 §6.6). Each has its own ledger: cash, bank,
 * wallet and card under the cash_bank_group (1100), cash credit under current liabilities (R-13). Its book balance is
 * the ledger's posted balance; receipts and payments name it in payment_details.
 */
class MoneyAccount extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    public const TYPES = ['cash', 'bank', 'wallet', 'card', 'cash_credit'];

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'overdraft_limit' => 'decimal:2', 'use_for_receipts' => 'boolean', 'use_for_payments' => 'boolean', 'show_on_invoices' => 'boolean',
        'is_active' => 'boolean', 'last_reconciled_on' => 'date',
    ];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    /** The account number as shown outside the bank screens: "••" and the last 4 characters. */
    public function maskedNumber(): ?string
    {
        return $this->account_number ? '••'.substr($this->account_number, -4) : null;
    }
}
