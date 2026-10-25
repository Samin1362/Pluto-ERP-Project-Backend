<?php

namespace App\Domain\Sales;

use App\Models\CompanySetting;
use App\Models\SalesInvoice;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a sales invoice as an A4 PDF (E4; backlog P5.1-BE-05): the invoice per the company's invoice design (K7), or
 * the delivery challan without prices (GAP-12). Used by GET /sales-invoices/{id}/pdf and by the e-mail (P5.1-BE-06).
 */
final class InvoicePdf
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function render(SalesInvoice $invoice, string $variant = 'invoice'): string
    {
        $invoice->loadMissing(['lines', 'party', 'warehouse']);

        return Pdf::loadView('pdf.sales-invoice', ['invoice' => $invoice, 'variant' => $variant, 'company' => $this->current->company(),
            'settings' => CompanySetting::first()])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output(); // embeds only the glyphs used
    }

    public function filename(SalesInvoice $invoice, string $variant = 'invoice'): string
    {
        return ($variant === 'challan' ? 'challan-' : '').$invoice->number.'.pdf';
    }
}
