<?php

namespace App\Services\Sms;

interface SmsSender
{
    /** Sends one SMS. Throws on failure. */
    public function send(string $to, string $message): void;
}
