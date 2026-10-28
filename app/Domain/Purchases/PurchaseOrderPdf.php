<?php

namespace App\Domain\Purchases;

use App\Domain\Documents\TradeDocumentPdf;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Models\CompanySetting;
use App\Models\PurchaseOrder;

/** GET /purchase-orders/{id}/pdf and the e-mail attachment (F8 Preview, F11 Print; backlog P10.2-BE-01). In the order's currency. */
final class PurchaseOrderPdf
{
    public function __construct(private readonly TradeDocumentPdf $pdf) {}

    public function render(PurchaseOrder $po): string
    {
        $po->loadMissing(['lines', 'party', 'warehouse']);
        $format = CompanySetting::first()?->date_format ?: 'd M Y';
        $ccy = $po->currencyCode();
        $label = $ccy === 'BDT' ? 'Tk' : $ccy;
        $m = fn ($v) => Taka::format(Money::of((string) $v), symbol: false);
        $foreign = $ccy !== 'BDT';
        $totals = ['Subtotal' => $m($po->subtotal)];
        if ($po->discount_total->isPositive()) {
            $totals['Discount'] = '−'.$m($po->discount_total);
        }
        if ($po->tax_total->isPositive()) {
            $totals['Tax'] = $m($po->tax_total);
        }
        $totals["Total ({$label})"] = $m($foreign ? $po->total_foreign : $po->total);
        $lines = $po->lines->map(fn ($l) => [
            'line_no' => $l->line_no, 'code' => $l->product_code, 'name' => $l->product_name, 'description' => $l->description, 'quantity' => (string) $l->quantity,
            'unit' => $l->unit_name, 'price' => (string) Money::of((string) $l->unit_cost), 'discount_pct' => $l->discount_pct, 'amount' => (string) $l->line_total,
        ])->all();
        $stamp = match ($po->status) {
            'draft' => 'DRAFT', 'cancelled' => 'CANCELLED', default => null,
        };

        return $this->pdf->render($po, 'Purchase order', 'Order to', [
            'Order date' => $po->order_date->format($format),
            'Expected by' => $po->expected_date?->format($format),
            'Deliver to' => $po->warehouse?->name,
            'Currency' => $foreign ? "{$ccy} (planning rate {$po->exchange_rate})" : null,
        ], $lines, $totals, AmountInWords::of($foreign ? $po->total_foreign : $po->total, $ccy), $stamp,
            ['currency' => $label, 'priceLabel' => 'Cost', 'signLeft' => 'Supplier acknowledgement', 'footer' => 'Please quote this order number on your bill and delivery challan.']);
    }

    public function filename(PurchaseOrder $po): string
    {
        return $po->number.'.pdf';
    }
}
