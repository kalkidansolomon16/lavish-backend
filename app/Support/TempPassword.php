<?php

namespace App\Support;

/** Temporary passwords handed to new staff and students, e.g. "LA-K7QM42". Changed at first login. */
final class TempPassword
{
    public static function make(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I to avoid misreading

        $s = '';
        for ($i = 0; $i < 6; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'LA-'.$s;
    }
}
