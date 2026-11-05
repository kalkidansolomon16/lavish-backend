<?php

namespace App\Services\Payments;

use App\Enums\OrderPaymentMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(private ChapaGateway $chapa, private Notifier $notifier) {}

    /** The bill for an appointment or order. */
    public function createFor(Model $payable, Client $client, int $amount, string $description): Payment
    {
        return Payment::create([
            'client_id' => $client->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'description' => $description,
            'amount' => $amount,
            'status' => PaymentStatus::Unpaid,
        ]);
    }

    /** Reception / delivery records a payment taken at the desk or on delivery. */
    public function recordManual(Payment $payment, PaymentMethod $method, User $by): Payment
    {
        if (! $payment->isOpen()) {
            throw BusinessRuleException::make('payment_not_open', 'This payment is already '.$payment->status->value.'.');
        }

        return $this->markPaid($payment, $method, provider: null, reference: null, by: $by);
    }

    /**
     * Starts a Chapa checkout for the client. Returns the payment with checkout_url set.
     * Calling it again while pending simply starts a fresh checkout (new tx_ref).
     */
    public function startCheckout(Payment $payment, PaymentMethod $method): Payment
    {
        if (! $payment->isOpen()) {
            throw BusinessRuleException::make('payment_not_open', 'This payment is already '.$payment->status->value.'.');
        }
        if (! in_array($method->value, PaymentMethod::online(), true)) {
            throw BusinessRuleException::make('method_not_online', 'Choose Telebirr, CBE Birr or card to pay in the app.');
        }

        $payment->forceFill([
            'tx_ref' => 'LV-'.$payment->id.'-'.Str::upper(Str::random(8)),
            'method' => $method,
            'provider' => 'chapa',
            'status' => PaymentStatus::Pending,
        ])->save();

        $payment->checkout_url = $this->chapa->initialize($payment);
        $payment->save();

        return $payment;
    }

    /** Asks Chapa whether the payment went through and records it. Safe to call many times. */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->status === PaymentStatus::Paid || ! $payment->tx_ref) {
            return $payment;
        }

        $result = $this->chapa->verify($payment);
        if (! $result['paid']) {
            return $payment;
        }
        if ($result['amount'] !== null && (int) round($result['amount']) < $payment->amount) {
            report(new \RuntimeException("Chapa amount mismatch on payment {$payment->id}: {$result['amount']}"));

            return $payment;
        }

        return $this->markPaid($payment, $payment->method ?? PaymentMethod::Telebirr, 'chapa', $result['reference'], null);
    }

    public function markPaid(Payment $payment, PaymentMethod $method, ?string $provider, ?string $reference, ?User $by): Payment
    {
        DB::transaction(function () use ($payment, $method, $provider, $reference, $by) {
            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'method' => $method,
                'provider' => $provider,
                'provider_reference' => $reference,
                'paid_at' => now(),
                'recorded_by' => $by?->id,
                'checkout_url' => null,
            ])->save();
        });

        $payment->loadMissing('client.user', 'payable');
        $target = $payment->payable instanceof Order
            ? ['screen' => 'order', 'id' => $payment->payable_id]
            : ['screen' => 'appointment', 'id' => $payment->payable_id];
        $extra = $payment->payable instanceof Appointment && $payment->payable->videoLinkVisibleToClient()
            ? ' Your video link is now available in the app.' : '';
        $this->notifier->client($payment->client, new LavishNotification(
            'payment.received', 'Payment received',
            'We received ETB '.number_format($payment->amount)." for {$payment->description} ({$method->label()}).{$extra}",
            'verified', $target,
        ));

        return $payment;
    }

    /** The appointment/order was cancelled: drop what's owed, or flag a refund if already paid. */
    public function cancelFor(?Payment $payment, string $why): void
    {
        if (! $payment) {
            return;
        }
        if ($payment->status === PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::RefundDue, 'meta' => array_merge($payment->meta ?? [], ['refund_reason' => $why])]);
            $this->notifier->staff('payments.collect', new LavishNotification(
                'payment.refund_due', 'Refund needed',
                "{$payment->client->name} paid ETB ".number_format($payment->amount)." for {$payment->description}, which was cancelled ({$why}).",
                'currency_exchange', null, '/payments',
            ));
        } elseif ($payment->isOpen()) {
            $payment->update(['status' => PaymentStatus::Cancelled, 'checkout_url' => null]);
        }
    }

    public function markRefunded(Payment $payment, User $by): Payment
    {
        if ($payment->status !== PaymentStatus::RefundDue) {
            throw BusinessRuleException::make('payment_not_refundable', 'Only payments marked “refund due” can be refunded.');
        }
        $payment->update(['status' => PaymentStatus::Refunded, 'meta' => array_merge($payment->meta ?? [], ['refunded_by' => $by->id, 'refunded_at' => now()->toIso8601String()])]);

        return $payment;
    }

    /** Maps the shop checkout choice to a payment method (pay on delivery is collected as cash). */
    public static function methodForOrder(OrderPaymentMethod $m): PaymentMethod
    {
        return match ($m) {
            OrderPaymentMethod::Telebirr => PaymentMethod::Telebirr,
            OrderPaymentMethod::CbeBirr => PaymentMethod::CbeBirr,
            OrderPaymentMethod::Card => PaymentMethod::Card,
            OrderPaymentMethod::PayOnDelivery => PaymentMethod::Cash,
        };
    }
}
