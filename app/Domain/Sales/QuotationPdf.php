<?php

namespace App\Domain\Sales;

use App\Domain\Documents\TradeDocumentPdf;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Models\CompanySetting;
use App\Models\Quotation;

/** GET /quotations/{id}/pdf and the e-mail attachment (E10 Preview, E13 Print; backlog P10.1-BE-01). */
final class QuotationPdf
{
    public function __construct(private readonly TradeDocumentPdf $pdf) {}

    public function render(Quotation $q): string
    {
        $q->loadMissing(['lines', 'party', 'warehouse']);
        $format = CompanySetting::first()?->date_format ?: 'd M Y';
        $m = fn ($v) => Taka::format(Money::of((string) $v), symbol: false);
        $totals = ['Subtotal' => $m($q->subtotal)];
        if ($q->discount_total->isPositive()) {
            $totals['Discount'] = '−'.$m($q->discount_total);
        }
        if ($q->tax_total->isPositive()) {
            $totals['Tax'] = $m($q->tax_total);
        }
        if (! $q->rounding_adjustment->isZero()) {
            $totals['Rounding'] = ($q->rounding_adjustment->isNegative() ? '−' : '').$m($q->rounding_adjustment->abs());
        }
        $totals['Total (Tk)'] = $m($q->total);
        $lines = $q->lines->map(fn ($l) => [
            'line_no' => $l->line_no, 'code' => $l->product_code, 'name' => $l->product_name, 'description' => $l->description, 'quantity' => (string) $l->quantity,
            'unit' => $l->unit_name, 'price' => (string) $l->unit_price, 'discount_pct' => $l->discount_pct, 'amount' => (string) $l->line_total,
        ])->all();
        $stamp = match ($q->status) {
            'draft' => 'DRAFT', 'declined' => 'DECLINED', 'expired' => 'EXPIRED', default => null,
        };

        return $this->pdf->render($q, 'Quotation', 'Quotation for', [
            'Date' => $q->quotation_date->format($format),
            'Valid until' => $q->valid_until->format($format),
        ], $lines, $totals, AmountInWords::of($q->total, 'BDT'), $stamp, ['notesLabel' => 'Terms of offer', 'signLeft' => 'Accepted by (signature, date)']);
    }

    public function filename(Quotation $q): string
    {
        return $q->number.'.pdf';
    }
}
