<?php

namespace App\Domain\Purchases;

use App\Domain\Shared\AuditLogger;
use App\Mail\DocumentMail;
use App\Models\PurchaseOrder;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\Mail;

/**
 * POST /purchase-orders/{id}/send (F8 "Send to supplier"; backlog P10.2-BE-01): marks the order sent and queues the
 * e-mail with the PDF attached. Recipients are the given list, or the supplier's e-mail when the list is empty (422 when
 * there is none). Audited as purchase_order_sent.
 */
final class PurchaseOrderMailer
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly PurchaseOrderPdf $pdf,
        private readonly CurrentCompany $current,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<string>  $to  @param  list<string>  $cc */
    public function send(PurchaseOrder $po, array $to, array $cc = [], ?string $message = null): PurchaseOrder
    {
        $po->loadMissing('party');
        $to = $to ?: array_filter([$po->party?->email]);
        if ($to === []) {
            throw new ApiProblem('validation_failed', errors: ['to' => ["{$po->party?->name} has no e-mail on file; add an address"]]);
        }
        $po = $this->orders->markSent($po);
        $company = $this->current->company();
        $name = $company->trading_name ?: $company->legal_name;
        $expected = $po->expected_date ? ' We expect delivery by '.$po->expected_date->format('j M Y').'.' : '';
        Mail::to($to)->cc($cc)->queue(new DocumentMail(
            subjectLine: "Purchase order {$po->number} from {$name}",
            greetingName: $po->party_snapshot['name'] ?? $po->party?->name ?? '',
            body: "Please find attached our purchase order {$po->number}.{$expected} Please quote the order number on your bill.",
            note: $message,
            companyName: $name,
            pdf: base64_encode($this->pdf->render($po)),
            filename: $this->pdf->filename($po),
        ));
        $this->audit->record('purchase_order_sent', $po, null, ['to' => array_values($to), 'cc' => array_values($cc)]);

        return $po;
    }
}
