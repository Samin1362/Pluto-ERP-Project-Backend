<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A party's statement of account (backlog P6.1-BE-05); queued with the PDF rendered at send time. */
class PartyStatementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $partyName,
        public string $companyName,
        public string $periodFrom,
        public string $periodTo,
        public string $pdf,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Statement of account from {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.party-statement');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => base64_decode($this->pdf), $this->filename)->withMime('application/pdf')];
    }
}
