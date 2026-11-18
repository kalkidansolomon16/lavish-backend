<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushChannel
{
    // The sender is looked up on every send, so changing PUSH_DRIVER (or swapping it in tests) takes effect at once.

    public function send(object $notifiable, LavishNotification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }
        $data = array_filter([
            'nid' => $notification->id, // same id as in GET /notifications, so the app never shows one twice
            'type' => $notification->type,
            'screen' => $notification->target['screen'] ?? null,
            'id' => isset($notification->target['id']) ? (string) $notification->target['id'] : null,
            'path' => $notification->path,
        ]);
        try {
            app(PushSender::class)->send($notifiable, $notification->title, $notification->body, $data);
        } catch (Throwable $e) {
            Log::warning('Push failed: '.$e->getMessage());
        }
    }
}
