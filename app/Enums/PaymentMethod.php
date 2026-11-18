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
    case GiftCard = 'gift_card';   // the whole bill was covered by a gift card (+ voucher/points)
    case Voucher = 'voucher';      // the whole bill was covered by a Lavish Rewards voucher or points

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Telebirr => 'Telebirr',
            self::CbeBirr => 'CBE Birr',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::GiftCard => 'Gift card',
            self::Voucher => 'Lavish Rewards',
        };
    }

    /** Money taken at the desk or on delivery (what staff can record). */
    public static function desk(): array
    {
        return [self::Cash->value, self::Telebirr->value, self::CbeBirr->value, self::Card->value, self::BankTransfer->value];
    }

    /** Methods the client can pay with online through Chapa. */
    public static function online(): array
    {
        return [self::Telebirr->value, self::CbeBirr->value, self::Card->value];
    }
}
