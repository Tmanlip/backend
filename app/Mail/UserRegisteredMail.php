<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $password)
    {
        $this->user = $user;
        $this->password = $password; // Send plain password (optional, not recommended in prod)
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Account is Ready',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.user_registered',
            with: [
                'loginUrl' => $this->resolveFrontendLoginUrl(),
            ],
        );
    }

    private function resolveFrontendLoginUrl(): string
    {
        $frontendBase = trim((string) config('app.frontend_url', ''));

        // Prevent accidentally shipping localhost links in production emails.
        if ($frontendBase !== '' && $this->isLocalhostUrl($frontendBase) && app()->environment('production')) {
            $fallbackFromCors = $this->resolveFrontendFromCorsOrigins();
            if ($fallbackFromCors !== null) {
                $frontendBase = $fallbackFromCors;
            }
        }

        if ($frontendBase === '') {
            $frontendBase = trim((string) config('app.url', ''));
        }

        $frontendBase = rtrim($frontendBase, '/');

        if ($frontendBase === '') {
            return '/login';
        }

        if (preg_match('#/login/?$#i', $frontendBase) === 1) {
            return $frontendBase;
        }

        return $frontendBase . '/login';
    }

    private function resolveFrontendFromCorsOrigins(): ?string
    {
        $rawOrigins = (string) env('CORS_ALLOWED_ORIGINS', '');
        if (trim($rawOrigins) === '') {
            return null;
        }

        $origins = array_values(array_filter(array_map('trim', explode(',', $rawOrigins))));
        foreach ($origins as $origin) {
            if ($origin === '') {
                continue;
            }

            if ($this->isLocalhostUrl($origin)) {
                continue;
            }

            return rtrim($origin, '/');
        }

        return null;
    }

    private function isLocalhostUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
