<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsRate;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Import letter of credit (M3/M4; 04 §6.9; 06 §12): lots, documents, amendments, charges and payments hang off it. */
class LetterOfCredit extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'amount' => AsMoney::class, 'tolerance_pct' => 'decimal:4', 'planning_rate' => AsRate::class, 'margin_pct' => 'decimal:4', 'quantity' => AsQuantity::class,
        'partial_shipments' => 'boolean', 'transhipment' => 'boolean', 'confirmation_required' => 'boolean', 'record_shipments' => 'boolean',
        'application_date' => 'date', 'latest_shipment_date' => 'date', 'expiry_date' => 'date', 'maturity_date' => 'date', 'opened_on' => 'date', 'closed_on' => 'date',
    ];

    protected $table = 'letters_of_credit';

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function issuingBank(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'issuing_bank_account_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LcDocument::class)->orderBy('id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(LcShipment::class)->orderBy('lot_no');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(LcAmendment::class)->orderBy('amendment_no');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(LcCharge::class)->orderBy('charge_date')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LcPayment::class)->orderBy('payment_date')->orderBy('id');
    }
}
