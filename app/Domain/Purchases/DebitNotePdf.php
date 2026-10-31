<?php

namespace App\Domain\Purchases;

use App\Domain\Documents\TradeDocumentPdf;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Models\CompanySetting;
use App\Models\DebitNote;
use App\Models\PurchaseBillLine;
use Brick\Math\RoundingMode;

/**
 * GET /debit-notes/{id}/pdf (F7 Print, F13; backlog P10.4-BE-02): a return's note lists the goods sent back at their
 * value; any other note shows one line with its reason.
 */
final class DebitNotePdf
{
    private const REASONS = ['goods_returned' => 'Goods returned', 'short_supply' => 'Short supply', 'price_difference' => 'Price difference',
        'quality_claim' => 'Quality claim'];

    public function __construct(private readonly TradeDocumentPdf $pdf) {}

    public function render(DebitNote $n): string
    {
        $n->loadMissing(['party', 'bill', 'purchaseReturn.lines']);
        $format = CompanySetting::first()?->date_format ?: 'd M Y';
        $ret = $n->purchaseReturn;
        if ($ret) {
            $bill = PurchaseBillLine::whereIn('id', $ret->lines->pluck('purchase_bill_line_id'))->get()->keyBy('id');
            $lines = $ret->lines->map(fn ($l) => ['line_no' => $l->line_no, 'code' => $bill[$l->purchase_bill_line_id]->product_code,
                'name' => $bill[$l->purchase_bill_line_id]->product_name, 'description' => str_replace('_', ' ', ucfirst($l->reason)), 'quantity' => (string) $l->quantity,
                'unit' => $bill[$l->purchase_bill_line_id]->unit_name, 'price' => (string) Money::of($l->value->toBigDecimal()->dividedBy($l->quantity->toBigDecimal(), 2, RoundingMode::HalfUp)),
                'amount' => (string) $l->value])->all();
        } else {
            $lines = [['line_no' => 1, 'code' => '', 'name' => self::REASONS[$n->reason] ?? $n->reason, 'description' => null, 'quantity' => '1', 'unit' => '',
                'price' => (string) $n->amount, 'amount' => (string) $n->amount]];
        }
        $stamp = match ($n->status) {
            'draft' => 'DRAFT — NOT POSTED', 'cancelled' => 'CANCELLED', default => null,
        };

        return $this->pdf->render($n, 'Debit note', 'Debit to', [
            'Date' => $n->note_date->format($format),
            'Against bill' => $n->bill ? "{$n->bill->number} (your bill {$n->bill->supplier_bill_no})" : null,
            'Return' => $ret?->number,
            'Reason' => self::REASONS[$n->reason] ?? $n->reason,
        ], $lines, ['Debit (Tk)' => Taka::format(Money::of((string) $n->amount), symbol: false)], AmountInWords::of($n->amount, 'BDT'), $stamp,
            ['signLeft' => 'Supplier acknowledgement']);
    }
}
