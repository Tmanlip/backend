<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CaseActivityMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $actionLabel,
        public readonly string $caseTitle,
        public readonly int $caseId,
        public readonly string $summary,
        public readonly ?string $actorName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Case Update: {$this->actionLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.case_activity',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}