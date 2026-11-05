<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The one notification class for the whole system. Stored in the `notifications` table
 * (the in-app inbox for the web bell and the mobile Notifications screen) and pushed to devices.
 *
 * Payload (GET /api/v1/notifications → data):
 *   type    stable machine key, e.g. "appointment.approved"
 *   title   short heading
 *   body    one or two sentences
 *   icon    Material Symbols name (the app maps it to Icons.<name>_rounded)
 *   target  where tapping goes: { screen: "appointment", id: 202 } (mobile) — null if nowhere
 *   path    web portal route, e.g. "/appointments" — null for client/student notifications
 */
class LavishNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public string $icon = 'notifications',
        public ?array $target = null,
        public ?string $path = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'target' => $this->target,
            'path' => $this->path,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }
}
