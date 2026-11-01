<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The password-reset link (S2; FR-003; backlog P15.1-BE-07). Queued; the link holds the token, valid 60 minutes, once. */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $link, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Pluto password');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset');
    }
}
