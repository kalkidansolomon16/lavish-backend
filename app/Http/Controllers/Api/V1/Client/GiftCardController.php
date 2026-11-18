<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Resources\GiftCardResource;
use App\Http\Resources\PaymentResource;
use App\Models\GiftCard;
use App\Models\LoyaltySetting;
use App\Models\Service;
use App\Services\GiftCardService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gift cards in the client app: buy one for someone, see cards received / sent, add a card by code or QR.
 *
 * Buying: POST /client/gift-cards → { data: card (pending_payment), payment, code }. Pay the bill (upload the
 * receipt, or Chapa when on). Once paid the card waits for Lavish (awaiting_activation); reception checks and
 * activates it, and the recipient gets it by email / SMS with "use it from … to …".
 */
class GiftCardController extends ClientController
{
    public function __construct(private GiftCardService $cards) {}

    /** Amounts, designs and treatments offered on the Buy screen. */
    public function options(): JsonResponse
    {
        $s = LoyaltySetting::current();

        return response()->json(['data' => [
            'amounts' => $s->gift_card_amounts ?? [1000, 2500, 5000, 10000],
            'min_amount' => 100,
            'designs' => GiftCard::DESIGNS,
            'services' => Service::query()->where('is_online', false)->orderBy('sort')->get(['id', 'name', 'price']),
            'expiry_months' => $s->gift_card_expiry_months,
            'terms' => [
                'Lavish checks and activates your gift card after it is paid; the recipient then gets it by email or SMS.',
                'Gift cards can be used for any Lavish treatment or product until they expire.',
                'Unused balance stays on the card for your next visit.',
                'Gift cards can’t be refunded or exchanged for cash.',
                'Buying a gift card earns no points — the person who spends it on a treatment earns them.',
            ],
        ]]);
    }

    /** My Gift Cards: received (with balance) and sent (with delivery status). */
    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $received = $client->giftCards()->whereNotIn('status', ['pending_payment', 'awaiting_activation'])
            ->with('service')->latest()->get();
        $sent = $client->giftCardsSent()->with('service', 'payment')->latest()->get();

        return response()->json(['data' => [
            'received' => GiftCardResource::collection($received),
            'sent' => GiftCardResource::collection($sent),
            'total_balance' => (int) $received->filter->usable()->sum('balance'),
        ]]);
    }

    public function show(Request $request, GiftCard $giftCard): GiftCardResource
    {
        $client = $this->client($request);
        abort_unless(in_array($client->id, [$giftCard->recipient_client_id, $giftCard->purchaser_client_id], true), 404);

        return new GiftCardResource($giftCard->load('service', 'payment', 'ledger'));
    }

    /** Buy a gift card for someone (or yourself). */
    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['monetary', 'service'])],
            'amount' => ['required_if:type,monetary', 'nullable', 'integer', 'min:100', 'max:500000'],
            'service_id' => ['required_if:type,service', 'nullable', 'exists:services,id'],
            'design' => ['nullable', Rule::in(GiftCard::DESIGNS)],
            'message' => ['nullable', 'string', 'max:300'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required_without:recipient_email', 'nullable', 'string', 'max:20'],
            'recipient_email' => ['required_without:recipient_phone', 'nullable', 'email', 'max:160'],
            'delivery' => ['nullable', Rule::in(['sms', 'email'])],
            'deliver_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        [$card, $payment, $code] = $this->cards->buy($this->client($request), $data, $payments);

        return response()->json([
            'data' => new GiftCardResource($card),
            'payment' => new PaymentResource($payment),
            'code' => $code, // shown once — the recipient also receives it by SMS / email
        ], 201);
    }

    /** Add a card to my account by code or QR scan (rate-limited). */
    public function claim(Request $request): GiftCardResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        return new GiftCardResource($this->cards->claim($this->client($request), $data['code'])->load('service'));
    }

    /** Check a card's balance without adding it (rate-limited). */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $card = $this->cards->findByCode($data['code']);
        abort_if(! $card || $card->status === 'pending_payment', 404, 'We couldn’t find a gift card with that code.');
        abort_if($card->status === 'awaiting_activation', 422, 'This gift card is paid and waiting for Lavish to activate it.');
        abort_if($card->status === 'in_stock', 422, 'This card hasn’t been activated yet. It becomes active when it is paid for at the Lavish front desk.');

        return response()->json(['data' => [
            'masked_code' => $card->maskedCode(), 'label' => $card->label(), 'type' => $card->type,
            'balance' => $card->balance, 'status' => $card->usable() ? 'active' : ($card->expires_at?->isPast() ? 'expired' : $card->status),
            'expires_at' => $card->expires_at?->toIso8601String(),
        ]]);
    }
}
