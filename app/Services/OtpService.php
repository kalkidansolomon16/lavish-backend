<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;

/**
 * One-time SMS codes for phone verification (client sign-up) and password reset.
 *
 * In non-production environments using the "log" SMS driver the code is always
 * config('lavish.otp.dev_code') (123456 by default) so the app can be tested without SMS.
 */
class OtpService
{
    public function __construct(private Messenger $messenger) {}

    public function send(string $phone, OtpPurpose $purpose): void
    {
        $cfg = config('lavish.otp');

        $last = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->latest('id')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < $cfg['resend_seconds']) {
            $wait = $cfg['resend_seconds'] - (int) $last->created_at->diffInSeconds(now());
            throw BusinessRuleException::make('otp_too_soon', "Please wait {$wait} seconds before requesting a new code.", ['retry_after' => $wait], 429);
        }

        OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->useDevCode()
            ? (string) $cfg['dev_code']
            : str_pad((string) random_int(0, 10 ** $cfg['length'] - 1), $cfg['length'], '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($cfg['ttl_minutes']),
        ]);

        $this->messenger->sms($phone, "Your Lavish verification code is {$code}. It expires in {$cfg['ttl_minutes']} minutes. Never share this code.", logAs: "Verification code ({$purpose->value}) [code hidden]");
    }

    /** Throws if the code is wrong, expired or locked; consumes it on success. */
    public function verify(string $phone, OtpPurpose $purpose, string $code): void
    {
        $otp = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw BusinessRuleException::make('otp_expired', 'This code has expired. Please request a new one.');
        }
        if ($otp->attempts >= config('lavish.otp.max_attempts')) {
            throw BusinessRuleException::make('otp_locked', 'Too many wrong attempts. Please request a new code.');
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw BusinessRuleException::make('otp_invalid', 'The code is not correct.');
        }

        $otp->update(['consumed_at' => now()]);
    }

    private function useDevCode(): bool
    {
        return ! app()->isProduction() && config('lavish.sms.driver') === 'log';
    }
}
