<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quoted line with its product snapshot and calculated amounts; reached only through its quotation. */
class QuotationLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => AsQuantity::class, 'unit_price' => AsMoney::class, 'discount_pct' => 'decimal:4', 'discount_amount' => AsMoney::class,
        'tax_rate' => 'decimal:4', 'tax_amount' => AsMoney::class, 'net_amount' => AsMoney::class, 'line_total' => AsMoney::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
