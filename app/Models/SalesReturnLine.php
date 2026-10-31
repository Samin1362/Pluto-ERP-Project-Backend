<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Casts\AsUnitCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A returned quantity of one invoice line: its price, value (after discount, with tax) and, once posted, the cost it came back at. */
class SalesReturnLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => AsQuantity::class, 'unit_price' => AsMoney::class, 'value' => AsMoney::class, 'unit_cost' => AsUnitCost::class];

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
