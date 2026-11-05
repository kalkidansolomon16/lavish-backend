<?php

namespace App\Services\Push;

use App\Models\User;

interface PushSender
{
    /**
     * Sends a push notification to every registered device of the user.
     *
     * @param  array<string, string>  $data  string-only payload delivered to the app (FCM "data")
     */
    public function send(User $user, string $title, string $body, array $data = []): void;
}
