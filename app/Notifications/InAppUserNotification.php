<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InAppUserNotification extends Notification
{
    use Queueable;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => (string) ($this->payload['title'] ?? 'Notification'),
            'message' => (string) ($this->payload['message'] ?? ''),
            'category' => (string) ($this->payload['category'] ?? 'general'),
            'case_id' => $this->payload['case_id'] ?? null,
            'meeting_id' => $this->payload['meeting_id'] ?? null,
            'actor_name' => $this->payload['actor_name'] ?? null,
            'meta' => $this->payload['meta'] ?? null,
        ];
    }
}
