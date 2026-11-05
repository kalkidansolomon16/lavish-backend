<?php

namespace App\Enums;

enum OrderPaymentMethod: string
{
    use HasValues;

    case PayOnDelivery = 'pay_on_delivery';
    case Telebirr = 'telebirr';
    case CbeBirr = 'cbe_birr';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::PayOnDelivery => 'Pay on delivery',
            self::Telebirr => 'Telebirr',
            self::CbeBirr => 'CBE Birr',
            self::Card => 'Card',
        };
    }
}
