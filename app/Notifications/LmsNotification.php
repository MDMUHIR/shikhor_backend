<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Generic in-app notification (database channel, queued). */
class LmsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $kind,
        private readonly string $title,
        private readonly string $message,
        private readonly array $data = []
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
