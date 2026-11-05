<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
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
        $payments = $this->client($request)->payments()->latest()->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'outstanding' => (int) $payments->filter->isOpen()->sum('amount'),
                'paid' => (int) $payments->where('status', PaymentStatus::Paid)->sum('amount'),
            ],
        ]);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);

        return new PaymentResource($payment);
    }

    public function checkout(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);
        $data = $request->validate(['method' => ['required', Rule::in(PaymentMethod::online())]]);

        return new PaymentResource($this->payments->startCheckout($payment->load('client'), PaymentMethod::from($data['method'])));
    }

    public function verify(Request $request, Payment $payment): PaymentResource
    {
        $this->own($request, $payment);

        return new PaymentResource($this->payments->confirm($payment->load('client')));
    }
}
