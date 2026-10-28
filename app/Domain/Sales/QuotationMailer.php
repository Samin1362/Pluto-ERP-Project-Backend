<?php

namespace App\Domain\Sales;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Taka;
use App\Mail\DocumentMail;
use App\Models\Quotation;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\Mail;

/**
 * POST /quotations/{id}/send (E10 "Send quotation"; backlog P10.1-BE-01): marks the quotation sent and queues the e-mail
 * with the PDF attached. Recipients are the given list, or the customer's e-mail when the list is empty (422 when there
 * is none). Audited as quotation_sent.
 */
final class QuotationMailer
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly QuotationPdf $pdf,
        private readonly CurrentCompany $current,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<string>  $to  @param  list<string>  $cc */
    public function send(Quotation $q, array $to, array $cc = [], ?string $message = null): Quotation
    {
        $q->loadMissing('party');
        $to = $to ?: array_filter([$q->party?->email]);
        if ($to === []) {
            throw new ApiProblem('validation_failed', errors: ['to' => ["{$q->party?->name} has no e-mail on file; add an address"]]);
        }
        $q = $this->quotations->markSent($q);
        $company = $this->current->company();
        $name = $company->trading_name ?: $company->legal_name;
        $validUntil = $q->valid_until->format('j M Y');
        Mail::to($to)->cc($cc)->queue(new DocumentMail(
            subjectLine: "Quotation {$q->number} from {$name}",
            greetingName: $q->party_snapshot['name'] ?? $q->party?->name ?? '',
            body: "Please find attached our quotation {$q->number} for ".Taka::format($q->total).", valid until {$validUntil}.",
            note: $message,
            companyName: $name,
            pdf: base64_encode($this->pdf->render($q)),
            filename: $this->pdf->filename($q),
        ));
        $this->audit->record('quotation_sent', $q, null, ['to' => array_values($to), 'cc' => array_values($cc)]);

        return $q;
    }
}
