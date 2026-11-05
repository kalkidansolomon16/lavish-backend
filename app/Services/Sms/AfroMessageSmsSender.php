<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AfroMessage (https://afromessage.com), an Ethiopian SMS gateway.
 * Configure AFROMESSAGE_TOKEN, AFROMESSAGE_IDENTIFIER_ID and AFROMESSAGE_SENDER in .env,
 * then set SMS_DRIVER=afromessage. Verify the request format against your AfroMessage
 * account documentation before going live.
 */
class AfroMessageSmsSender implements SmsSender
{
    public function __construct(private array $config) {}

    public function send(string $to, string $message): void
    {
        $response = Http::withToken($this->config['token'])
            ->timeout(15)
            ->get(rtrim($this->config['base_url'], '/').'/send', [
                'from' => $this->config['identifier_id'],
                'sender' => $this->config['sender'],
                'to' => $to,
                'message' => $message,
            ]);

        if (! $response->successful() || $response->json('acknowledge') !== 'success') {
            throw new RuntimeException('AfroMessage: '.$response->body());
        }
    }
}
