<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A polite reminder of a customer's overdue invoices (PEP-516), the statement PDF attached (rendered at send time). */
class PaymentReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<array{number: string, invoice_date: string, due_date: string, total: string, balance_due: string, days_late: int}>  $invoices */
    public function __construct(
        public string $partyName,
        public string $companyName,
        public array $invoices,
        public string $totalDue,
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment reminder from {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payment-reminder');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => base64_decode($this->pdf), $this->filename)->withMime('application/pdf')];
    }
}
