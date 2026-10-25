<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice line with its product snapshot (code, name, unit as invoiced) and calculated amounts. No company_id of its
 * own: it is always reached through its invoice. income_ledger_id is where the line's net posts (06 §5); unit_cost and
 * cost_amount are the average cost taken at posting for COGS (BR-016).
 */
class SalesInvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => AsQuantity::class, 'unit_price' => AsMoney::class, 'discount_pct' => 'decimal:4', 'discount_amount' => AsMoney::class,
        'tax_rate' => 'decimal:4', 'tax_amount' => AsMoney::class, 'net_amount' => AsMoney::class, 'line_total' => AsMoney::class,
        'unit_cost' => AsUnitCost::class, 'cost_amount' => AsMoney::class, 'returned_quantity' => AsQuantity::class,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
