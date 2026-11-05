<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushChannel
{
    public function __construct(private PushSender $push) {}

    public function send(object $notifiable, LavishNotification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }
        $data = array_filter([
            'type' => $notification->type,
            'screen' => $notification->target['screen'] ?? null,
            'id' => isset($notification->target['id']) ? (string) $notification->target['id'] : null,
            'path' => $notification->path,
        ]);
        try {
            $this->push->send($notifiable, $notification->title, $notification->body, $data);
        } catch (Throwable $e) {
            Log::warning('Push failed: '.$e->getMessage());
        }
    }
}
