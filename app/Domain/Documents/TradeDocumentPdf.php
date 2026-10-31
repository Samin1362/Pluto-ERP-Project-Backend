<?php

namespace App\Domain\Documents;

use App\Models\CompanySetting;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a trade document (quotation, purchase order, credit or debit note; backlog P10) on the A4 layout of the
 * invoice (K7 accent colour, footer, signature) through resources/views/pdf/trade-document.blade.php. Each document's
 * own PDF class decides the title, the meta rows, the lines and the totals it shows.
 */
final class TradeDocumentPdf
{
    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * @param  array<string, ?string>  $meta  label => value (empty values are skipped)
     * @param  list<array{line_no: int, code: string, name: string, description: ?string, quantity: string, unit: string, price: string, discount_pct?: ?string, amount: string}>  $lines
     * @param  array<string, string>  $totals  label => formatted amount; the last row is the grand total
     * @param  array<string, mixed>  $extra  optional view values: currency, priceLabel, notesLabel, signLeft, footer
     */
    public function render(Model $doc, string $title, string $partyLabel, array $meta, array $lines, array $totals, ?string $words, ?string $stamp, array $extra = []): string
    {
        return Pdf::loadView('pdf.trade-document', ['doc' => $doc, 'title' => $title, 'partyLabel' => $partyLabel, 'meta' => $meta, 'lines' => $lines,
            'totals' => $totals, 'words' => $words, 'stamp' => $stamp, 'company' => $this->current->company(), 'settings' => CompanySetting::first()] + $extra)
            ->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }
}
