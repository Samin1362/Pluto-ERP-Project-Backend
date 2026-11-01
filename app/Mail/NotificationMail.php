<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The e-mail copy of a notification, for users who switched e-mail on for the event (K12; FR-013; backlog
 * P14.1-BE-04), and the daily summary. Queued; plain values only, so the worker needs no company context.
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<string>  $lines */
    public function __construct(
        public string $subjectLine,
        public string $greetingName,
        public array $lines,
        public ?string $link,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notification');
    }
}
