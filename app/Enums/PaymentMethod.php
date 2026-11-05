<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use HasValues;

    case Cash = 'cash';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Telebirr => 'Telebirr',
            self::CbeBirr => 'CBE Birr',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /** Methods the client can pay with online through Chapa. */
    public static function online(): array
    {
        return [self::Telebirr->value, self::CbeBirr->value, self::Card->value];
    }
}
