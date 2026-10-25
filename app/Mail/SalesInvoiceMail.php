<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice e-mail (backlog P5.1-BE-06). Queued; carries plain values and the PDF (base64) rendered at send time,
 * so the queue worker needs no company context.
 */
class SalesInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $number,
        public string $companyName,
        public string $customerName,
        public string $total,
        public string $dueDate,
        public ?string $note,
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->number} from {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.sales-invoice');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => base64_decode($this->pdf), $this->filename)->withMime('application/pdf')];
    }
}
