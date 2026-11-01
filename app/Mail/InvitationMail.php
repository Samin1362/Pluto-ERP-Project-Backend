<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An invitation to join a company (K9, S3; FR-006; backlog P15.1-BE-05). Queued; the link holds the only copy of the token. */
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ?string $name, public string $inviter, public string $companyName, public string $role, public string $link, public string $expires) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviter} invited you to {$this->companyName} on Pluto");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation');
    }
}
