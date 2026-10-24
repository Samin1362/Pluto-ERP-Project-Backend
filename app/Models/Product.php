<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Product or service (D1–D3). Quantities and costs live in stock_balances and change only through postings. */
class Product extends Model
{
    use BelongsToCompany, HasLockVersion, SoftDeletes, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = [
        'selling_price' => 'decimal:2', 'wholesale_price' => 'decimal:2', 'last_purchase_cost' => 'decimal:4',
        'track_stock' => 'boolean', 'is_active' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function stockGroup(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class);
    }

    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class);
    }

    public function incomeLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'income_ledger_id');
    }

    public function expenseLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'expense_ledger_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'preferred_supplier_id');
    }

    public function warehouseSettings(): HasMany
    {
        return $this->hasMany(ProductWarehouseSetting::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
