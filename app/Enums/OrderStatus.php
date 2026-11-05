<?php

namespace App\Enums;

/**
 * Delivery tracker. Lavish E-commerce moves an order one step at a time; every step
 * is stamped in order_status_events, emailed to the client and pushed to the app.
 */
enum OrderStatus: string
{
    use HasValues;

    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Out = 'out';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** The happy path, in order. */
    public static function flow(): array
    {
        return [self::Placed, self::Confirmed, self::Preparing, self::Ready, self::Out, self::Delivered];
    }

    public function next(): ?self
    {
        $flow = self::flow();
        $i = array_search($this, $flow, true);

        return $i === false ? null : ($flow[$i + 1] ?? null);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled], true);
    }

    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    /** Staff-facing label (web). */
    public function label(): string
    {
        return match ($this) {
            self::Placed => 'New order',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready to deliver',
            self::Out => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Client-facing title (app + email). */
    public function clientLabel(): string
    {
        return match ($this) {
            self::Placed => 'Order placed',
            self::Confirmed => 'Order confirmed',
            self::Preparing => 'Your order is being prepared',
            self::Ready => 'Ready for delivery',
            self::Out => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Order cancelled',
        };
    }

    /** Web button text for moving INTO this status. */
    public function actionLabel(): ?string
    {
        return match ($this) {
            self::Confirmed => 'Confirm order',
            self::Preparing => 'Start preparing',
            self::Ready => 'Mark ready to deliver',
            self::Out => 'Hand to courier',
            self::Delivered => 'Confirm delivery',
            default => null,
        };
    }
}
