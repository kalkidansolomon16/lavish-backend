<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Doctor;
use App\Models\User;
use App\Notifications\LavishNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Who gets told what. Staff are targeted by permission (everyone who can act on it),
 * doctors personally, clients through their app account (walk-ins without the app get nothing here).
 */
class Notifier
{
    /** Every active staff member holding $permission, optionally except the person who did it. */
    public function staff(string $permission, LavishNotification $n, ?User $except = null): void
    {
        $users = User::query()->withPermission($permission)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get();
        Notification::send($users, $n);
    }

    public function doctor(?Doctor $doctor, LavishNotification $n): void
    {
        $doctor?->user?->notify($n);
    }

    public function client(Client $client, LavishNotification $n): void
    {
        $client->user?->notify($n);
    }

    public function user(?User $user, LavishNotification $n): void
    {
        $user?->notify($n);
    }
}
