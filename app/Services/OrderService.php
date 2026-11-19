<?php

namespace App\Services;

use App\Enums\OrderPaymentMethod;
use App\Enums\PaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrescriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shop orders. Status moves one step at a time along OrderStatus::flow().
 * Stock is reserved while the order is open, checked on confirm, deducted on delivery.
 * Every step is stamped, emailed to the client and pushed to the app.
 */
class OrderService
{
    public function __construct(
        private InventoryService $inventory,
        private PaymentService $payments,
        private Messenger $messenger,
        private Notifier $notifier,
        private LoyaltyService $loyalty,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    public function place(Client $client, array $lines, array $delivery, OrderPaymentMethod $method, ?Prescription $rx = null, string $source = 'app', ?User $by = null): Order
    {
        $order = DB::transaction(function () use ($client, $lines, $delivery, $method, $rx, $source) {
            $lines = collect($lines)->groupBy('product_id')->map(fn ($g, $id) => ['product_id' => (int) $id, 'quantity' => (int) $g->sum('quantity')])->values();
            $products = Product::query()->active()->withStock()->whereIn('id', $lines->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');

            $items = $lines->map(function ($l) use ($products) {
                $p = $products->get($l['product_id']);
                if (! $p) {
                    throw BusinessRuleException::make('product_unavailable', 'One of the products is no longer sold.', ['product_id' => $l['product_id']]);
                }
                if ($p->available() < $l['quantity']) {
                    throw BusinessRuleException::make('insufficient_stock', "Only {$p->available()} × {$p->name} left.", ['product_id' => $p->id, 'available' => $p->available()]);
                }

                return ['product_id' => $p->id, 'product_name' => $p->name, 'unit_price' => $p->price, 'quantity' => $l['quantity'], 'line_total' => $p->price * $l['quantity']];
            });
            if ($items->isEmpty()) {
                throw BusinessRuleException::make('empty_order', 'Your cart is empty.');
            }

            $subtotal = (int) $items->sum('line_total');
            $fee = (int) config('lavish.delivery_fee');
            $order = Order::create([
                'client_id' => $client->id,
                'prescription_id' => $rx?->id,
                'status' => OrderStatus::Placed,
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'discount' => 0,
                'total' => $subtotal + $fee,
                'payment_method' => $method,
                'delivery_address' => $delivery['address'],
                'delivery_note' => $delivery['note'] ?? null,
                'contact_phone' => $delivery['phone'] ?? $client->phone,
                'source' => $source,
            ]);
            $order->items()->createMany($items->all());
            $this->payments->createFor($order, $client, $order->total, "Order #{$order->id}");
            $rx?->update(['status' => PrescriptionStatus::Ordered]);

            return $order;
        });

        $this->stamp($order, OrderStatus::Placed, $by);
        $this->notifier->staff('orders.deliver', new LavishNotification('order.placed', 'New order',
            "New order #{$order->id} from {$client->name} — ETB ".number_format($order->total).'.', 'shopping_bag', null, '/orders?focus='.$order->id));

        return $order->refresh();
    }

    /** E-commerce turns a prescription's Lavish products into an order (address confirmed later by phone). */
    public function fromPrescription(Prescription $rx, User $by): Order
    {
        if ($rx->status !== PrescriptionStatus::Sent) {
            throw BusinessRuleException::make('already_ordered', 'This prescription was already ordered.');
        }
        $lines = $rx->items->whereNotNull('product_id')->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity])->values()->all();
        if (! $lines) {
            throw BusinessRuleException::make('nothing_to_order', 'Nothing to order — all items are outside Lavish products.');
        }

        return $this->place($rx->client, $lines, ['address' => 'To be confirmed with client'], OrderPaymentMethod::PayOnDelivery, $rx, 'prescription', $by);
    }

    /**
     * Moves the order to the next step. Returns products that are now at/below their reorder level.
     *
     * @param  array{name?: string, phone?: string}|null  $courier  required when handing to the courier
     * @return list<Product>
     */
    /**
     * @param  array{collected?: bool, method?: string}|null  $collection  pay-on-delivery orders: did the courier collect
     *                                                                   the money, and how. Not collected → stays unpaid.
     */
    public function advance(Order $order, User $by, ?array $courier = null, ?string $overrideReason = null, ?array $collection = null): array
    {
        $next = $order->status->next();
        if (! $next) {
            throw BusinessRuleException::make('order_closed', 'This order is already '.$order->status->value.'.');
        }
        // Delivered only after the client confirmed in the app that they received it — or with a reason.
        if ($next === OrderStatus::Delivered && ! $order->received_at) {
            if (blank($overrideReason)) {
                throw BusinessRuleException::make('awaiting_client_confirmation', 'The client hasn’t confirmed they received the order yet. Wait for them to tap “I received my order”, or give a reason (e.g. confirmed by phone).');
            }
            $order->received_override = $overrideReason;
        }
        $order->loadMissing('items.product');

        if (in_array($next, [OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            foreach ($order->items as $item) {
                if ($item->product && $item->product->stockTotal() < $item->quantity) {
                    throw BusinessRuleException::make('insufficient_stock', "Not enough stock for {$item->product_name} — restock first.", ['product_id' => $item->product_id]);
                }
            }
        }
        if ($next === OrderStatus::Out) {
            if (empty($courier['name'])) {
                throw BusinessRuleException::make('courier_required', 'Add the courier’s name first.');
            }
            $order->fill(['courier_name' => $courier['name'], 'courier_phone' => $courier['phone'] ?? null]);
        }

        // Pay on delivery: staff must say whether the money was collected (never assumed).
        $podOpen = $next === OrderStatus::Delivered && $order->payment_method === OrderPaymentMethod::PayOnDelivery && $order->payment?->isOpen();
        if ($podOpen && ! isset($collection['collected'])) {
            throw BusinessRuleException::make('collection_required', 'Pay on delivery: did the courier collect the payment? Choose “Collected” (and how) or “Not paid yet”.');
        }
        $collectMethod = $podOpen && ! empty($collection['collected'])
            ? (PaymentMethod::tryFrom($collection['method'] ?? 'cash') ?? PaymentMethod::Cash) : null;

        $low = [];
        DB::transaction(function () use ($order, $next, $by, &$low, $collectMethod) {
            if ($next === OrderStatus::Delivered) {
                foreach ($order->items as $item) {
                    if (! $item->product) {
                        continue;
                    }
                    $this->inventory->deduct($item->product, $item->quantity, 'Order delivered', '#'.$order->id, $by);
                    $p = $item->product->fresh();
                    if ($p->stockTotal() <= $p->reorder_level) {
                        $low[] = $p;
                    }
                }
                $order->delivered_at = now();
                $payment = $order->payment;
                if ($collectMethod && $payment?->isOpen()) {
                    $this->payments->recordManual($payment, $collectMethod, $by);
                }
            }
            $order->status = $next;
            $order->save();
        });

        $this->stamp($order, $next, $by);
        if ($next === OrderStatus::Delivered) {
            $this->loyalty->creditOrder($order->refresh());
            // Delivered but not paid: remind the client and keep it on reception's "not paid" list.
            if ($podOpen && ! $collectMethod && $order->payment?->isOpen()) {
                $order->loadMissing('client');
                $due = 'ETB '.number_format($order->payment->dueAmount());
                $this->notifier->client($order->client, new LavishNotification('payment.due_now', 'Please pay for your order',
                    "Your order #{$order->id} was delivered. Please pay {$due} (Telebirr, CBE Birr, bank transfer or cash) and upload your receipt in the app.", 'payments', ['screen' => 'payment', 'id' => $order->payment->id]));
                $this->notifier->staff('payments.collect', new LavishNotification('payment.collect_now', 'Delivered order not paid',
                    "{$order->client->name}’s order #{$order->id} was delivered but not paid ({$due}).", 'point_of_sale', null, '/payments?tab=owing'));
            }
        }

        return $low;
    }

    /** The client taps "I received my order" in the app (while it is out for delivery or ready). */
    public function confirmReceived(Order $order): Order
    {
        if (! in_array($order->status, [OrderStatus::Out, OrderStatus::Ready], true)) {
            throw BusinessRuleException::make('not_out_for_delivery', $order->status === OrderStatus::Delivered ? 'This order is already delivered.' : 'You can confirm once the order is on its way to you.');
        }
        if (! $order->received_at) {
            $order->update(['received_at' => now()]);
            $order->loadMissing('client');
            $this->notifier->staff('orders.deliver', new LavishNotification('order.received', 'Client received the order',
                "{$order->client->name} confirmed they received order #{$order->id}. Confirm the delivery in Orders.", 'inventory_2', null, '/orders'));
        }

        return $order;
    }

    public function cancel(Order $order, User $by, ?string $reason = null): Order
    {
        if (! $order->status->isOpen()) {
            throw BusinessRuleException::make('order_closed', 'This order is already '.$order->status->value.'.');
        }
        $order->update(['status' => OrderStatus::Cancelled, 'cancel_reason' => $reason]);
        $this->payments->cancelFor($order->payment, 'order cancelled');
        $this->stamp($order, OrderStatus::Cancelled, $by, $reason);

        return $order;
    }

    public function resendLatestEmail(Order $order): ?OutboundMessage
    {
        $last = $order->emails()->latest('id')->first();

        return $last ? $this->messenger->email($last->to, $last->subject, $last->body, $order) : null;
    }

    /** History entry + email + app notification for a status change. */
    private function stamp(Order $order, OrderStatus $status, ?User $by, ?string $note = null): void
    {
        $order->events()->create(['status' => $status, 'user_id' => $by?->id, 'note' => $note]);
        $order->loadMissing('client.user', 'items');
        [$subject, $body] = $this->email($order, $status, $note);
        $this->messenger->email($order->client->email, $subject, $body, $order);

        $this->notifier->client($order->client, new LavishNotification('order.'.$status->value, $status->clientLabel(),
            $this->pushText($order, $status, $note), $status === OrderStatus::Out ? 'local_shipping' : 'inventory_2', ['screen' => 'order', 'id' => $order->id]));
    }

    private function pushText(Order $order, OrderStatus $status, ?string $note): string
    {
        $n = '#'.$order->id;

        return match ($status) {
            OrderStatus::Placed => "We received order {$n}.",
            OrderStatus::Confirmed => "Order {$n} is confirmed.",
            OrderStatus::Preparing => "Our team is packing order {$n}.",
            OrderStatus::Ready => "Order {$n} is packed and waiting for the courier.",
            OrderStatus::Out => "Order {$n} is on its way with ".($order->courier_name ?? 'our courier').'. Please keep your phone nearby.',
            OrderStatus::Delivered => "Order {$n} was delivered. Enjoy!",
            OrderStatus::Cancelled => "Order {$n} was cancelled".($note ? ": {$note}" : '.'),
        };
    }

    /** @return array{0: string, 1: string} subject + plain-text body (same wording as the web prototype) */
    private function email(Order $order, OrderStatus $status, ?string $note): array
    {
        $first = Str::before($order->client->name, ' ') ?: 'there';
        $n = '#'.$order->id;
        $total = 'ETB '.number_format($order->total);
        $courier = $order->courier_name ? $order->courier_name.($order->courier_phone ? " ({$order->courier_phone})" : '') : 'our courier';
        $paid = $order->payment?->status === PaymentStatus::Paid;

        [$subject, $text] = match ($status) {
            OrderStatus::Placed => ["We received your order {$n}", "Thank you for shopping with Lavish. We’ve received your order ({$order->itemsSummary()}) — total {$total}. We’ll email you again as soon as it’s confirmed."],
            OrderStatus::Confirmed => ["Your order {$n} is confirmed", 'Good news — your order is confirmed. Our team will start preparing your products shortly.'],
            OrderStatus::Preparing => ["Your order {$n} is being prepared", 'We’re carefully preparing and packing your products right now.'],
            OrderStatus::Ready => ["Your order {$n} is ready for delivery", "Your order is packed and waiting for the courier. It will be delivered to: {$order->delivery_address}."],
            OrderStatus::Out => ["Your order {$n} is out for delivery", "Your order is on its way with {$courier}. Please keep your phone nearby.".(! $paid && $order->payment_method === OrderPaymentMethod::PayOnDelivery ? " Please have {$total} ready to pay on delivery." : '')],
            OrderStatus::Delivered => ["Your order {$n} was delivered", 'Your order has been delivered. Enjoy your products — and tell us how you like them in the app!'],
            OrderStatus::Cancelled => ["Your order {$n} was cancelled", 'Your order has been cancelled'.($note ? ": {$note}" : '.').' If you already paid, our team will contact you about your refund.'],
        };

        return [$subject, "Hi {$first},\n\n{$text}\n\nTrack your order any time in the Lavish app → Profile → My orders (tracking code {$order->trackingCode()}).\n\nWith care,\nLavish Medical Spa"];
    }
}
