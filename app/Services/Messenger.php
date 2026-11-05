<?php

namespace App\Services;

use App\Models\OutboundMessage;
use App\Services\Sms\SmsSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends SMS and email and records every message in outbound_messages.
 * A failed send never breaks the business action that triggered it — it is logged as failed.
 */
class Messenger
{
    public function __construct(private SmsSender $sms) {}

    /**
     * @param  string|null  $logAs  what to store in the log instead of $body — use it when the SMS
     *                              contains a secret (temporary password, OTP code)
     */
    public function sms(string $to, string $body, ?Model $related = null, ?string $logAs = null): OutboundMessage
    {
        return $this->record('sms', $to, null, $logAs ?? $body, $related, fn () => $this->sms->send($to, $body));
    }

    public function email(?string $to, string $subject, string $body, ?Model $related = null): ?OutboundMessage
    {
        if (! $to) {
            return null;
        }

        return $this->record('email', $to, $subject, $body, $related, fn () => Mail::raw($body, fn ($m) => $m->to($to)->subject($subject)));
    }

    private function record(string $channel, string $to, ?string $subject, string $body, ?Model $related, callable $send): OutboundMessage
    {
        $status = 'sent';
        $error = null;
        try {
            $send();
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning("Outbound {$channel} to {$to} failed: {$error}");
        }

        return OutboundMessage::create([
            'channel' => $channel,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'status' => $status,
            'error' => $error,
        ]);
    }
}
