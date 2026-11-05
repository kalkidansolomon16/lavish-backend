<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\ChapaGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentService $payments, private ChapaGateway $chapa) {}

    /**
     * Chapa calls this (GET callback_url with ?trx_ref=…, and POST webhooks with JSON tx_ref).
     * We look up the payment and verify it with Chapa's API — the request body itself is never trusted.
     */
    public function chapa(Request $request): JsonResponse
    {
        if ($request->isMethod('post') && ! $this->chapa->validSignature($request->getContent(), $request->header('x-chapa-signature'), $request->header('chapa-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $txRef = $request->input('tx_ref') ?? $request->input('trx_ref') ?? $request->query('trx_ref');
        $payment = $txRef ? Payment::query()->with('client')->where('tx_ref', $txRef)->first() : null;
        if ($payment) {
            $this->payments->confirm($payment);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Development only (CHAPA_MODE=fake): stands in for Chapa's checkout page. Signed URL, expires in 1 hour.
     * Opening it marks the payment paid, so the whole app flow can be tested without real money.
     */
    public function fakeCheckout(Request $request, string $txRef): Response
    {
        abort_if(app()->isProduction() || ! $this->chapa->isFake(), 404);
        $payment = Payment::query()->with('client')->where('tx_ref', $txRef)->firstOrFail();
        $payment->update(['meta' => array_merge($payment->meta ?? [], ['fake_paid' => true])]);
        $this->payments->confirm($payment);
        $amount = number_format($payment->amount);
        $return = e(config('lavish.chapa.return_url').'?tx_ref='.$txRef);

        return response(<<<HTML
            <!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
            <body style="font-family:Roboto,sans-serif;text-align:center;padding:48px;color:#262626;background:#f6f5f4">
              <h2 style="color:#0a7379">Test payment successful</h2>
              <p>ETB {$amount} · {$payment->description}</p>
              <p style="color:#808285">No money was taken — this is the development checkout.</p>
              <a href="{$return}" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#0a7379;color:#fff;border-radius:16px;text-decoration:none">Return to the app</a>
            </body>
            HTML);
    }
}
