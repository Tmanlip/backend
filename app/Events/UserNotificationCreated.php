<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $title,
        public readonly string $message,
        public readonly string $category,
    ) {}

    /**
     * Broadcast on the private user channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.' . $this->userId),
        ];
    }

    /**
     * Event name seen by the JS client.
     */
    public function broadcastAs(): string
    {
        return 'UserNotificationCreated';
    }

    /**
     * Data sent to the frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'title'    => $this->title,
            'message'  => $this->message,
            'category' => $this->category,
        ];
    }
}
