<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lavish Rewards & gift cards. On cPanel add one cron job: * * * * * php /path/to/artisan schedule:run
Schedule::command('lavish:loyalty nightly')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('lavish:loyalty daily')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('lavish:loyalty campaigns')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('lavish:appointment-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('lavish:gift-cards')->everyFiveMinutes()->withoutOverlapping();
