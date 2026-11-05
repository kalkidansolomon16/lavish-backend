<?php

namespace App\Support;

/**
 * Phone numbers are stored in E.164 (+251911223344). People type them in many ways —
 * "0911 22 33 44", "911223344", "+251 911 22 33 44", "251911223344" — and all of them
 * must find the same account.
 */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $plus = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D+/', '', $input);

        if ($plus) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '251') && strlen($digits) === 12) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $e164 = '+251'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $e164 = '+251'.$digits;
        } else {
            return null;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $e164) ? $e164 : null;
    }

    /** Local Ethiopian format used as the student username, e.g. 0912456789. */
    public static function local(string $e164): string
    {
        return str_starts_with($e164, '+251') ? '0'.substr($e164, 4) : ltrim($e164, '+');
    }
}
