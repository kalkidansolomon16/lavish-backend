<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use HasValues;

    case Unpaid = 'unpaid';
    case Pending = 'pending';       // Chapa checkout started, waiting for confirmation
    case Paid = 'paid';
    case Failed = 'failed';
    case RefundDue = 'refund_due';  // paid, then the booking/order was cancelled; reception must refund
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';   // unpaid and no longer owed
}
