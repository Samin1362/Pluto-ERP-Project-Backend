<?php

namespace App\Domain\Sales;

use App\Domain\Documents\TradeDocumentPdf;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\SalesInvoiceLine;

/**
 * GET /credit-notes/{id}/pdf (E9 Print, E15; backlog P10.3-BE-02): a return's note lists the returned items at their
 * value; any other note shows one line with its reason.
 */
final class CreditNotePdf
{
    private const REASONS = ['goods_returned' => 'Goods returned', 'price_correction' => 'Price correction', 'damaged_on_delivery' => 'Damaged on delivery',
        'goodwill' => 'Goodwill allowance'];

    public function __construct(private readonly TradeDocumentPdf $pdf) {}

    public function render(CreditNote $n): string
    {
        $n->loadMissing(['party', 'invoice', 'salesReturn.lines']);
        $format = CompanySetting::first()?->date_format ?: 'd M Y';
        $m = fn ($v) => Taka::format(Money::of((string) $v), symbol: false);
        $ret = $n->salesReturn;
        if ($ret && $ret->total->isEqualTo($n->amount)) {
            $inv = SalesInvoiceLine::whereIn('id', $ret->lines->pluck('sales_invoice_line_id'))->get()->keyBy('id');
            $lines = $ret->lines->map(fn ($l) => ['line_no' => $l->line_no, 'code' => $inv[$l->sales_invoice_line_id]->product_code,
                'name' => $inv[$l->sales_invoice_line_id]->product_name, 'description' => str_replace('_', ' ', ucfirst($l->reason)), 'quantity' => (string) $l->quantity,
                'unit' => $inv[$l->sales_invoice_line_id]->unit_name, 'price' => (string) $l->unit_price, 'amount' => (string) $l->value])->all();
        } else {
            $lines = [['line_no' => 1, 'code' => '', 'name' => self::REASONS[$n->reason] ?? $n->reason, 'description' => null, 'quantity' => '1', 'unit' => '',
                'price' => (string) $n->amount, 'amount' => (string) $n->amount]];
        }
        $stamp = match ($n->status) {
            'draft' => 'DRAFT — NOT POSTED', 'cancelled' => 'CANCELLED', default => null,
        };

        return $this->pdf->render($n, 'Credit note', 'Credit to', [
            'Date' => $n->note_date->format($format),
            'Against invoice' => $n->invoice?->number,
            'Return' => $ret?->number,
            'Reason' => self::REASONS[$n->reason] ?? $n->reason,
        ], $lines, ['Credit (Tk)' => $m($n->amount)], AmountInWords::of($n->amount, 'BDT'), $stamp, ['signLeft' => 'Customer signature']);
    }
}
