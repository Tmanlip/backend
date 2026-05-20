<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminLogSeverityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $logData
     */
    public function __construct(
        public readonly array $logData,
    ) {
    }

    public function envelope(): Envelope
    {
        $severity = (string) ($this->logData['severity'] ?? 'UNKNOWN');
        $module = (string) ($this->logData['module'] ?? 'api');

        return new Envelope(
            subject: "[ASLAW Alert][{$severity}] {$module} event detected",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_log_severity_alert',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
