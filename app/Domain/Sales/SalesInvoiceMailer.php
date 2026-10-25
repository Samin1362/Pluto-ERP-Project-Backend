<?php

namespace App\Domain\Sales;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Taka;
use App\Mail\SalesInvoiceMail;
use App\Models\SalesInvoice;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails a posted invoice to the customer (E5 "E-mail to customer"; backlog P5.1-BE-06). The PDF is rendered now, in
 * the company's context, and the mail is queued with it attached (the worker has no tenant context). Recipients are
 * the given list, or the customer's e-mail when the list is empty. Drafts and cancelled invoices are not sent.
 * Audited as invoice_sent. Locally the mail lands in Mailpit; the live stack logs it until SMTP is chosen.
 */
final class SalesInvoiceMailer
{
    public function __construct(private readonly InvoicePdf $pdf, private readonly CurrentCompany $current, private readonly AuditLogger $audit) {}

    /** @param  list<string>  $to  @param  list<string>  $cc */
    public function send(SalesInvoice $invoice, array $to, array $cc = [], ?string $message = null): void
    {
        if ($invoice->status !== 'posted') {
            throw new ApiProblem('invalid_transition', "{$invoice->number} is {$invoice->status}; only a posted invoice can be e-mailed.", extra: ['status' => $invoice->status]);
        }
        $invoice->loadMissing('party');
        $to = $to ?: array_filter([$invoice->party?->email]);
        if ($to === []) {
            throw new ApiProblem('validation_failed', errors: ['to' => ["{$invoice->party?->name} has no e-mail on file; add an address"]]);
        }
        $company = $this->current->company();
        Mail::to($to)->cc($cc)->queue(new SalesInvoiceMail(
            number: $invoice->number,
            companyName: $company->trading_name ?: $company->legal_name,
            customerName: $invoice->party_snapshot['name'] ?? $invoice->party?->name ?? '',
            total: Taka::format($invoice->total),
            dueDate: $invoice->due_date->format('j M Y'),
            note: $message,
            pdf: base64_encode($this->pdf->render($invoice)),
            filename: $this->pdf->filename($invoice),
        ));
        $this->audit->record('invoice_sent', $invoice, null, ['to' => array_values($to), 'cc' => array_values($cc)]);
    }
}
