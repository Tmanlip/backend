<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDeactivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $userName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Account Has Been Deactivated',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account_deactivated',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
