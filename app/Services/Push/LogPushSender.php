<?php

namespace App\Services\Push;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Development driver. Real push (Firebase Cloud Messaging HTTP v1) needs the Firebase
 * project's service-account file; add an FcmPushSender implementing PushSender and bind it
 * in AppServiceProvider when PUSH_DRIVER=fcm. Until then the app still receives every
 * notification through GET /api/v1/notifications.
 */
class LogPushSender implements PushSender
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $devices = $user->deviceTokens()->count();
        Log::info("[PUSH to user {$user->id} ({$devices} devices)] {$title}: {$body}", $data);
    }
}
