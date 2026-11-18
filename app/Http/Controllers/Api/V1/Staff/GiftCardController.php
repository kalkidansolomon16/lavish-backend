<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\GiftCardResource;
use App\Models\GiftCard;
use App\Models\Service;
use App\Services\GiftCardService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Gift cards in the web portal.
 *   page.giftcards     — list and card history
 *   giftcards.sell     — sell printed / digital cards, look a card up by code
 *   giftcards.promo / giftcards.void / giftcards.stock — promo cards, void & reissue, printed card batches
 * Printed cards: POST stock/generate (new numbers for the printer) or stock/import (numbers already printed),
 * then POST stock/sell when one is sold — scan its barcode, set the amount, take the money.
 * Staff never see a full code in lists — only "•••• 7K2M". The full code is returned once when a card
 * is sold or reissued, so it can be printed or read out.
 */
class GiftCardController extends StaffController
{
    public function __construct(private GiftCardService $cards) {}

    /** ?status=active|redeemed|expired|void|pending_payment &type= &q= (recipient / buyer name or phone, or last 4 of the code) */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = GiftCard::query()->with('service', 'seller', 'activator')
            ->when($request->string('status')->value(), fn ($q, $s) => $q->whereIn('status', explode(',', $s)), fn ($q) => $q->where('status', '!=', 'in_stock'))
            ->when($request->string('batch')->value(), fn ($q, $b) => $q->where('batch', $b))
            ->when($request->string('type')->value(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('q')->trim()->value(), function ($q, $t) {
                $q->where(fn ($q) => $q->where('recipient_name', 'like', "%{$t}%")->orWhere('purchaser_name', 'like', "%{$t}%")
                    ->orWhere('recipient_phone', 'like', "%{$t}%")->orWhere('code_last4', strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $t), -4))));
            })
            ->latest('id');

        return GiftCardResource::collection($q->paginate($this->perPage($request, 30)))->additional(['meta' => [
            'liability' => (int) GiftCard::query()->where('status', 'active')->sum('balance'),
            'in_stock' => GiftCard::query()->where('status', 'in_stock')->count(),
            // For the status chips: how many cards are in each state.
            'counts' => GiftCard::query()->where('status', '!=', 'in_stock')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
                ->map(fn ($n) => (int) $n)->put('expired_unused', GiftCard::query()->where('status', 'active')->where('expires_at', '<', now())->count()),
            'batches' => GiftCard::query()->whereNotNull('batch')->selectRaw("batch, count(*) as total, sum(case when status = 'in_stock' then 1 else 0 end) as unsold, min(created_at) as created_at")
                ->groupBy('batch')->orderByDesc('created_at')->limit(30)->get()
                ->map(fn ($b) => ['batch' => $b->batch, 'total' => (int) $b->total, 'unsold' => (int) $b->unsold, 'created_at' => $b->created_at]),
        ]]);
    }

    public function show(GiftCard $giftCard): GiftCardResource
    {
        return new GiftCardResource($giftCard->load('service', 'seller', 'activator', 'payment', 'purchaser', 'recipient', 'ledger.creator'));
    }

    /**
     * Activate an e-gift card bought in the app (after checking it). If it isn't paid yet, give `method` to
     * record the payment (money at the desk / receipt checked) and activate in one step.
     */
    public function activate(Request $request, GiftCard $giftCard, PaymentService $payments): GiftCardResource
    {
        $data = $request->validate(['method' => ['nullable', Rule::in(PaymentMethod::desk())]]);
        if (! empty($data['method'])) {
            abort_unless($request->user()->hasPermission('payments.collect') || $request->user()->hasPermission('giftcards.sell'), 403, 'You can’t record payments.');
        }
        $card = $this->cards->activateByStaff($giftCard, $request->user(), isset($data['method']) ? PaymentMethod::from($data['method']) : null, $payments);

        return new GiftCardResource($card->load('service', 'seller', 'activator', 'payment', 'purchaser', 'recipient', 'ledger.creator'));
    }

    /** Use the card at the visit: { amount, service_id?, note? } (a treatment card is used up in one go). */
    public function useCard(Request $request, GiftCard $giftCard): GiftCardResource
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'service_id' => ['nullable', 'exists:services,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $card = $this->cards->useAtDesk($giftCard, (int) ($data['amount'] ?? 0), isset($data['service_id']) ? Service::find($data['service_id']) : null, $data['note'] ?? null, $request->user());

        return new GiftCardResource($card->load('service', 'seller', 'activator', 'payment', 'purchaser', 'recipient', 'ledger.creator'));
    }

    /**
     * Sell a card at the desk (type monetary | service) and take the money now, or issue a free promo card
     * (type promo, needs giftcards.promo + a reason).
     */
    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(GiftCard::TYPES)],
            'amount' => ['required_unless:type,service', 'nullable', 'integer', 'min:100', 'max:500000'],
            'service_id' => ['required_if:type,service', 'nullable', 'exists:services,id'],
            'method' => ['required_unless:type,promo', 'nullable', Rule::in(PaymentMethod::desk())],
            'purchaser_client_id' => ['nullable', 'exists:clients,id'],
            'purchaser_name' => ['nullable', 'string', 'max:120'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_client_id' => ['nullable', 'exists:clients,id'],
            'message' => ['nullable', 'string', 'max:300'],
            'design' => ['nullable', Rule::in(GiftCard::DESIGNS)],
            'delivery' => ['required', Rule::in(['print', 'sms', 'email'])],
            'deliver_at' => ['nullable', 'date', 'after_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'reason' => ['required_if:type,promo', 'nullable', 'string', 'max:255'],
        ]);
        if ($data['delivery'] === 'sms' && empty($data['recipient_phone'])) {
            throw BusinessRuleException::make('recipient_phone_required', 'Enter the recipient’s phone number to send the card by SMS.');
        }
        if ($data['delivery'] === 'email' && empty($data['recipient_email'])) {
            throw BusinessRuleException::make('recipient_email_required', 'Enter the recipient’s email to send the card by email.');
        }

        if ($data['type'] === 'promo') {
            abort_unless($request->user()->hasPermission('giftcards.promo'), 403, 'Only managers can issue promotional cards.');
            [$card, $code] = $this->cards->issuePromo($data, $request->user(), $data['reason']);
        } else {
            [$card, $code] = $this->cards->sell($data, PaymentMethod::from($data['method']), $request->user(), $payments);
        }

        return response()->json([
            'data' => new GiftCardResource($card->load('service', 'seller', 'ledger.creator')),
            'code' => $code, // shown once: print it or read it out
        ], 201);
    }

    /** New printed cards: returns the batch and the numbers (once) to send to the printer as CSV. */
    public function generateStock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'batch' => ['nullable', 'string', 'max:40'],
            'design' => ['nullable', Rule::in(GiftCard::DESIGNS)],
        ]);
        [$batch, $codes] = $this->cards->generateStock($data['count'], $data['batch'] ?? null, $data['design'] ?? null, $request->user());

        return response()->json(['data' => ['batch' => $batch, 'codes' => $codes]], 201);
    }

    /** Cards already printed with their own numbers (one per line / comma-separated, or from a CSV column). */
    public function importStock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codes' => ['required', 'array', 'min:1', 'max:5000'],
            'codes.*' => ['string', 'max:40'],
            'batch' => ['nullable', 'string', 'max:40'],
            'design' => ['nullable', Rule::in(GiftCard::DESIGNS)],
        ]);

        return response()->json(['data' => $this->cards->importStock($data['codes'], $data['batch'] ?? null, $data['design'] ?? null, $request->user())], 201);
    }

    /** Sell a printed card: scanned/typed number + amount (or treatment) + payment method. */
    public function sellStock(Request $request, PaymentService $payments): GiftCardResource
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::in(['monetary', 'service'])],
            'amount' => ['required_if:type,monetary', 'nullable', 'integer', 'min:100', 'max:500000'],
            'service_id' => ['required_if:type,service', 'nullable', 'exists:services,id'],
            'method' => ['required', Rule::in(PaymentMethod::desk())],
            'purchaser_client_id' => ['nullable', 'exists:clients,id'],
            'purchaser_name' => ['nullable', 'string', 'max:120'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_client_id' => ['nullable', 'exists:clients,id'],
            'message' => ['nullable', 'string', 'max:300'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $card = $this->cards->sellStock($data['code'], $data, PaymentMethod::from($data['method']), $request->user(), $payments);

        return new GiftCardResource($card->load('service', 'seller', 'activator', 'payment', 'purchaser', 'recipient', 'ledger.creator'));
    }

    /** Find a card by its code (typed or scanned) to see balance and history. Rate-limited. */
    public function lookup(Request $request): GiftCardResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $card = $this->cards->findByCode($data['code']);
        abort_unless($card, 404, 'No gift card has that code.');

        return new GiftCardResource($card->load('service', 'seller', 'activator', 'payment', 'purchaser', 'recipient', 'ledger.creator'));
    }

    /** Void a lost card and (by default) reissue its balance under a new code. */
    public function void(Request $request, GiftCard $giftCard): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:255'],
            'reissue' => ['sometimes', 'boolean'],
        ]);
        [$new, $code] = $this->cards->void($giftCard, $data['reason'], $request->user(), $data['reissue'] ?? true);

        return response()->json([
            'data' => new GiftCardResource($giftCard->refresh()->load('service', 'ledger.creator')),
            'reissued' => $new ? new GiftCardResource($new->load('service', 'ledger.creator')) : null,
            'code' => $code, // the new card's code, shown once
        ]);
    }
}
