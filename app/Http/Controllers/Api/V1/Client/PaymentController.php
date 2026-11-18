<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paying in the app:
 *   1. POST /client/payments/{id}/checkout {method: telebirr|cbe_birr|card} → checkout_url
 *   2. Open checkout_url in a WebView/browser. When it redirects to the return URL (or the
 *      user closes it), call
 *   3. POST /client/payments/{id}/verify → the payment with its final status.
 * The server also confirms on its own through Chapa's callback/webhook.
 */
class PaymentController extends ClientController
{
    public function __construct(private PaymentService $payments) {}

    /** All payments + totals (Payments screen: outstanding vs paid). */
    public function index(Request $request): JsonResponse
    {
        $payments = $this->client($request)->payments()->with('receipts.attachments')->latest()->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'outstanding' => (int) $payments->filter->isOpen()->sum(fn ($p) => $p->dueAmount()), // what is left to pay in money
                'paid' => (int) $payments->where('status', PaymentStatus::Paid)->sum('amount'),
            ],
        ]);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);

        return new PaymentResource($payment->load('receipts.attachments'));
    }

    public function checkout(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);
        $data = $request->validate(['method' => ['required', Rule::in(PaymentMethod::online())]]);

        return new PaymentResource($this->payments->startCheckout($payment->load('client'), PaymentMethod::from($data['method'])));
    }

    /**
     * I paid outside the app (Telebirr, bank, cash…): upload the receipt. Multipart:
     * receipt (image/PDF ≤ 5 MB), method (cash|telebirr|cbe_birr|card|bank_transfer), reference?, amount?, note?
     * The payment becomes `in_review` until reception accepts (→ paid) or rejects it (→ unpaid, with a reason).
     */
    public function receipt(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);
        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'method' => ['required', Rule::in(PaymentMethod::desk())],
            'reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->payments->submitReceipt($payment, $request->user(), PaymentMethod::from($data['method']), $request->file('receipt'),
            $data['reference'] ?? null, $data['amount'] ?? null, $data['note'] ?? null);

        return new PaymentResource($payment->refresh()->load('receipts.attachments'));
    }

    /** Vouchers and gift cards I can use on this bill (with `applies`). */
    public function wallet(Request $request, Payment $payment, CheckoutService $checkout): JsonResponse
    {
        $this->own($request, $payment);

        return response()->json(['data' => $checkout->wallet($this->client($request), $payment->load('payable'))]);
    }

    /** Preview: what a voucher and/or gift card would take off. Body: voucher_id, gift_card_id. */
    public function quote(Request $request, Payment $payment, CheckoutService $checkout): JsonResponse
    {
        $this->own($request, $payment);

        return response()->json(['data' => $checkout->quote($payment, $this->credits($request))]);
    }

    /** Apply a voucher and/or gift card. If nothing is left the bill is paid; otherwise pay `due_amount` with Chapa. */
    public function apply(Request $request, Payment $payment, CheckoutService $checkout): PaymentResource
    {
        $this->own($request, $payment);

        return new PaymentResource($checkout->apply($payment, $this->credits($request))->refresh());
    }

    /** Take the applied voucher / gift card off again (before paying). */
    public function release(Request $request, Payment $payment, CheckoutService $checkout): PaymentResource
    {
        $this->own($request, $payment);
        abort_unless($payment->isOpen(), 422, 'This bill is already settled.');
        $checkout->release($payment, 'removed by the client');

        return new PaymentResource($payment->refresh());
    }

    private function credits(Request $request): array
    {
        return array_filter($request->validate([
            'voucher_id' => ['nullable', 'integer'],
            'gift_card_id' => ['nullable', 'integer'],
        ]));
    }

    public function verify(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);

        return new PaymentResource($this->payments->confirm($payment->load('client')));
    }
}
