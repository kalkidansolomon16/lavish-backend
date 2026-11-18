<?php

namespace App\Services\Payments;

use App\Enums\OrderPaymentMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Attachment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\CheckoutService;
use App\Services\GiftCardService;
use App\Services\LoyaltyService;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private ChapaGateway $chapa,
        private Notifier $notifier,
        private LoyaltyService $loyalty,
        private GiftCardService $giftCards,
    ) {}

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
    public function recordManual(Payment $payment, PaymentMethod $method, User $by, ?string $reference = null, ?UploadedFile $receipt = null): Payment
    {
        if (! $payment->isOpen()) {
            throw BusinessRuleException::make('payment_not_open', 'This payment is already '.$payment->status->value.'.');
        }
        if ($receipt || $reference) {
            $r = PaymentReceipt::create([
                'payment_id' => $payment->id, 'uploaded_by' => $by->id, 'source' => 'staff', 'method' => $method,
                'reference' => $reference, 'amount' => $payment->dueAmount(), 'status' => 'accepted', 'reviewed_by' => $by->id, 'reviewed_at' => now(),
            ]);
            if ($receipt) {
                Attachment::store($r, $receipt, $by->id, 'receipts/'.$payment->id);
            }
        }
        // Any receipt the client sent is settled by this desk payment.
        $payment->receipts()->where('status', 'pending')->update(['status' => 'accepted', 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

        return $this->markPaid($payment, $method, provider: null, reference: $reference, by: $by);
    }

    /** The client paid outside the app and uploads the receipt. The bill waits for reception to check it. */
    public function submitReceipt(Payment $payment, User $by, PaymentMethod $method, UploadedFile $file, ?string $reference, ?int $amount, ?string $note): PaymentReceipt
    {
        if (! $payment->isOpen()) {
            throw BusinessRuleException::make('payment_not_open', 'This payment is already '.$payment->status->value.'.');
        }
        if ($payment->dueAmount() === 0) {
            throw BusinessRuleException::make('nothing_to_pay', 'Nothing is left to pay on this bill.');
        }
        $receipt = DB::transaction(function () use ($payment, $by, $method, $file, $reference, $amount, $note) {
            // A new upload replaces one still waiting.
            $payment->receipts()->where('status', 'pending')->update(['status' => 'rejected', 'reject_reason' => 'Replaced by a newer receipt', 'reviewed_at' => now()]);
            $r = PaymentReceipt::create([
                'payment_id' => $payment->id, 'uploaded_by' => $by->id, 'source' => 'client', 'method' => $method,
                'reference' => $reference, 'amount' => $amount ?? $payment->dueAmount(), 'note' => $note, 'status' => 'pending',
            ]);
            Attachment::store($r, $file, $by->id, 'receipts/'.$payment->id);
            $payment->update(['status' => PaymentStatus::InReview, 'method' => $method]);

            return $r;
        });
        $payment->loadMissing('client');
        $this->notifier->staff('payments.collect', new LavishNotification('payment.receipt', 'Receipt to check',
            "{$payment->client->name} uploaded a {$method->label()} receipt for {$payment->description} (ETB ".number_format($receipt->amount).').',
            'receipt_long', null, '/payments'));

        return $receipt->load('attachments');
    }

    /** Reception checked the receipt: the bill is paid (points, gift card activation… follow as usual). */
    public function acceptReceipt(PaymentReceipt $receipt, User $by): Payment
    {
        if ($receipt->status !== 'pending') {
            throw BusinessRuleException::make('receipt_not_pending', 'This receipt was already '.$receipt->status.'.');
        }
        $receipt->update(['status' => 'accepted', 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

        return $this->markPaid($receipt->payment, $receipt->method, null, $receipt->reference, $by);
    }

    /** The receipt doesn't match: the bill is open again and the client is told why. */
    public function rejectReceipt(PaymentReceipt $receipt, User $by, string $reason): Payment
    {
        if ($receipt->status !== 'pending') {
            throw BusinessRuleException::make('receipt_not_pending', 'This receipt was already '.$receipt->status.'.');
        }
        $receipt->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'reject_reason' => $reason]);
        $payment = $receipt->payment->load('client');
        $payment->update(['status' => PaymentStatus::Unpaid]);
        $this->notifier->client($payment->client, new LavishNotification('payment.receipt_rejected', 'Receipt not accepted',
            "We couldn’t confirm your receipt for {$payment->description}: {$reason}. Please check and upload it again, or pay at reception.",
            'receipt_long', ['screen' => $payment->payable_type === 'order' ? 'order' : 'appointment', 'id' => $payment->payable_id]));

        return $payment;
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
        if (! config('lavish.payments.online_enabled')) {
            throw BusinessRuleException::make('online_payments_off', 'Online payment isn’t available yet. Pay by Telebirr, bank or at reception and upload your receipt.');
        }
        if ($payment->dueAmount() === 0) {
            throw BusinessRuleException::make('nothing_to_pay', 'Nothing is left to pay on this bill.');
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
        if ($result['amount'] !== null && (int) round($result['amount']) < $payment->dueAmount()) {
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
        $payable = $payment->payable;
        $target = match (true) {
            $payable instanceof Order => ['screen' => 'order', 'id' => $payment->payable_id],
            $payable instanceof GiftCard => ['screen' => 'gift_card', 'id' => $payment->payable_id],
            default => ['screen' => 'appointment', 'id' => $payment->payable_id],
        };
        $extra = $payable instanceof Appointment && $payable->videoLinkVisibleToClient()
            ? ' Your video link is now available in the app.' : '';
        $money = $payment->dueAmount();
        $text = $money > 0
            ? 'We received ETB '.number_format($money)." for {$payment->description} ({$method->label()})."
            : "{$payment->description} is fully covered by your ".($payment->gift_card_amount > 0 ? 'gift card' : 'Lavish Rewards').'.';
        $this->notifier->client($payment->client, new LavishNotification('payment.received', 'Payment received', $text.$extra, 'verified', $target));

        // Lavish Rewards & gift cards: points need the visit to be completed AND paid (whichever comes second).
        match (true) {
            $payable instanceof GiftCard => $this->giftCards->activate($payable, $by),
            $payable instanceof Appointment => $this->loyalty->creditVisit($payable),
            $payable instanceof Order => $this->loyalty->creditOrder($payable),
            default => null,
        };

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
            app(CheckoutService::class)->release($payment, $why);
            $payment->update(['status' => PaymentStatus::Cancelled, 'checkout_url' => null]);
        }
    }

    public function markRefunded(Payment $payment, User $by): Payment
    {
        if ($payment->status !== PaymentStatus::RefundDue) {
            throw BusinessRuleException::make('payment_not_refundable', 'Only payments marked “refund due” can be refunded.');
        }
        $cash = $payment->dueAmount();
        app(CheckoutService::class)->release($payment, 'refunded', $by);
        if (in_array($payment->payable_type, ['appointment', 'order'], true)) {
            $this->loyalty->reverseFor($payment->payable_type, $payment->payable_id, 'refunded', $by);
        }
        $payment->update(['status' => PaymentStatus::Refunded, 'meta' => array_merge($payment->meta ?? [], [
            'refunded_by' => $by->id, 'refunded_at' => now()->toIso8601String(), 'refunded_cash' => $cash,
        ])]);

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
