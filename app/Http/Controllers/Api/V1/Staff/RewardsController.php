<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\PointsLedgerResource;
use App\Http\Resources\RewardResource;
use App\Http\Resources\VoucherResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLoyalty;
use App\Models\EarnRule;
use App\Models\GiftCard;
use App\Models\GiftCardLedger;
use App\Models\LoyaltyCampaign;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTier;
use App\Models\PointsLedger;
use App\Models\Referral;
use App\Support\Phone;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Services\CheckoutService;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lavish Rewards in the web portal.
 *   page.rewards      — client loyalty profiles, members list, join / redeem at the desk
 *   rewards.manage    — settings, tiers, earn rules, catalog, campaigns, manual points (always with a reason, audited)
 *   finance.revenue   — reports (points & gift card liability)
 */
class RewardsController extends StaffController
{
    public function __construct(private LoyaltyService $loyalty) {}

    // ------------------------------------------------------------------ program settings

    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => LoyaltySetting::current()->makeHidden(['id', 'created_at']),
            'tiers' => LoyaltyTier::ladder()->map(fn ($t) => LoyaltyService::tierArray($t)),
            'earn_rules' => EarnRule::query()->with('service:id,name')->orderBy('type')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'type' => $r->type, 'service' => $r->service ? ['id' => $r->service->id, 'name' => $r->service->name] : null,
                'points' => $r->points, 'per_etb' => $r->per_etb, 'cap_per_visit' => $r->cap_per_visit, 'label' => $r->label,
                'active' => $r->active, 'active_from' => $r->active_from?->toDateString(), 'active_to' => $r->active_to?->toDateString(),
            ]),
            'rewards' => RewardResource::collection(Reward::query()->with('service', 'product', 'tierRequired')->orderBy('points_cost')->get()),
            'campaigns' => LoyaltyCampaign::query()->latest('starts_at')->get(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'point_value_etb' => ['sometimes', 'numeric', 'min:0.01', 'max:100'],
            'points_expiry_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'expiry_reminder_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'voucher_valid_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'max_points_per_visit' => ['sometimes', 'integer', 'min:1'],
            'max_points_per_day' => ['sometimes', 'integer', 'min:1'],
            'max_referrals_per_year' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'gift_card_expiry_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'promo_card_expiry_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'gift_card_low_balance_percent' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'gift_card_amounts' => ['sometimes', 'array', 'max:8'],
            'gift_card_amounts.*' => ['integer', 'min:100'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $s = LoyaltySetting::current();
        $before = $s->only(array_keys($data));
        $s->update(collect($data)->except('reason')->all());
        AuditLog::record($request->user(), 'rewards.settings', 'loyalty_settings', $s->id, $data['reason'], $before, $s->only(array_keys($before)));

        return $this->settings();
    }

    /** Replace the tier ladder values (names, thresholds, multipliers, benefits). */
    public function updateTiers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tiers' => ['required', 'array', 'min:1', 'max:6'],
            'tiers.*.id' => ['required', 'exists:loyalty_tiers,id'],
            'tiers.*.name' => ['required', 'string', 'max:40'],
            'tiers.*.min_points_12m' => ['required', 'integer', 'min:0'],
            'tiers.*.earn_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'tiers.*.service_discount_percent' => ['required', 'integer', 'min:0', 'max:50'],
            'tiers.*.points_never_expire' => ['required', 'boolean'],
            'tiers.*.benefits' => ['nullable', 'array'],
            'tiers.*.benefits.*' => ['string', 'max:120'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $before = LoyaltyTier::ladder()->map(fn ($t) => LoyaltyService::tierArray($t))->all();
        DB::transaction(function () use ($data) {
            foreach ($data['tiers'] as $t) {
                LoyaltyTier::findOrFail($t['id'])->update([...collect($t)->except('id', 'benefits')->all(), 'benefits' => $t['benefits'] ?? []]);
            }
        });
        AuditLog::record($request->user(), 'rewards.tiers', 'loyalty_tiers', null, $data['reason'], ['tiers' => $before], ['tiers' => $data['tiers']]);
        ClientLoyalty::query()->with('client')->get()->each(fn ($m) => $this->loyalty->refreshTier($m));

        return $this->settings();
    }

    /** Create / update one earn rule. */
    public function saveRule(Request $request, ?EarnRule $rule = null): JsonResponse
    {
        $data = $request->validate([
            'type' => [$rule ? 'sometimes' : 'required', Rule::in(EarnRule::TYPES)],
            'service_id' => ['nullable', 'exists:services,id'],
            'points' => [$rule ? 'sometimes' : 'required', 'integer', 'min:0', 'max:100000'],
            'per_etb' => ['nullable', 'integer', 'min:1'],
            'cap_per_visit' => ['nullable', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:160'],
            'active' => ['sometimes', 'boolean'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $before = $rule?->toArray();
        $rule ??= new EarnRule;
        $rule->fill(collect($data)->except('reason')->all())->save();
        AuditLog::record($request->user(), $before ? 'rewards.rule.update' : 'rewards.rule.create', 'earn_rule', $rule->id, $data['reason'], $before, $rule->toArray());

        return $this->settings();
    }

    /** Create / update a catalog reward. */
    public function saveReward(Request $request, ?Reward $reward = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$reward ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => [$reward ? 'sometimes' : 'required', 'integer', 'min:1'],
            'reward_type' => [$reward ? 'sometimes' : 'required', Rule::in(Reward::TYPES)],
            'value' => ['nullable', 'integer', 'min:0'],
            'service_id' => ['nullable', 'required_if:reward_type,free_service', 'exists:services,id'],
            'product_id' => ['nullable', 'required_if:reward_type,product', 'exists:products,id'],
            'tier_required_id' => ['nullable', 'exists:loyalty_tiers,id'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],
            'icon' => ['nullable', 'string', 'max:40'],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $before = $reward?->toArray();
        $reward ??= new Reward;
        $reward->fill(collect($data)->except('reason')->all())->save();
        AuditLog::record($request->user(), $before ? 'rewards.catalog.update' : 'rewards.catalog.create', 'reward', $reward->id, $data['reason'], $before, $reward->toArray());

        return $this->settings();
    }

    /** Create / update a campaign (double-points days, targeted offers). */
    public function saveCampaign(Request $request, ?LoyaltyCampaign $campaign = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$campaign ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'multiplier' => [$campaign ? 'sometimes' : 'required', 'numeric', 'min:1', 'max:10'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'tier_ids' => ['nullable', 'array'],
            'tier_ids.*' => ['integer', 'exists:loyalty_tiers,id'],
            'starts_at' => [$campaign ? 'sometimes' : 'required', 'date'],
            'ends_at' => [$campaign ? 'sometimes' : 'required', 'date', 'after:starts_at'],
            'active' => ['sometimes', 'boolean'],
            'notify_members' => ['sometimes', 'boolean'],
            'notice_title' => ['nullable', 'string', 'max:120'],
            'notice_body' => ['nullable', 'string', 'max:500'],
        ]);
        $before = $campaign?->toArray();
        // Moving an announced campaign's start into the future lets it announce again when it starts.
        if ($campaign?->notified_at && isset($data['starts_at']) && now()->lt($data['starts_at'])) {
            $data['notified_at'] = null;
        }
        $campaign ??= new LoyaltyCampaign(['created_by' => $request->user()->id]);
        $campaign->fill($data)->save();
        AuditLog::record($request->user(), $before ? 'rewards.campaign.update' : 'rewards.campaign.create', 'campaign', $campaign->id, null, $before, $campaign->toArray());

        return $this->settings();
    }

    // ------------------------------------------------------------------ clients

    /**
     * Members list, best first. ?q= (name / phone — searches every client, so walk-ins who haven't joined
     * yet can be found and enrolled) &tier_id=
     */
    public function members(Request $request): JsonResponse
    {
        $search = $request->string('q')->trim()->value();
        // A scanned member card (LVM0000012) finds that member directly.
        $memberId = $search ? LoyaltyService::clientIdFromMemberNumber($search) : null;
        $q = Client::query()->select('clients.*')
            ->leftJoin('client_loyalty', 'client_loyalty.client_id', '=', 'clients.id')
            ->with('loyalty.tier:id,name,key')
            // Members only (enrolled). ?all=1 or a search also finds clients who aren't enrolled yet.
            // ?requests=1: clients who asked to join in the app and are waiting for reception.
            ->when($request->boolean('requests'), fn ($q) => $q->whereNull('client_loyalty.joined_at')->whereNotNull('client_loyalty.requested_at'))
            ->when(! $search && ! $request->boolean('all') && ! $request->boolean('requests'), fn ($q) => $q->whereNotNull('client_loyalty.joined_at'))
            ->when($request->integer('tier_id'), fn ($q, $t) => $q->where('client_loyalty.tier_id', $t))
            ->when($memberId, fn ($q) => $q->where('clients.id', $memberId))
            ->when($search && ! $memberId, fn ($q) => $q->where(fn ($w) => $w->where('clients.name', 'like', "%{$search}%")
                ->orWhere('clients.phone', 'like', '%'.(ltrim(preg_replace('/\D/', '', $search), '0') ?: '#').'%')))
            ->orderByDesc('client_loyalty.points_12m')->orderBy('clients.name');
        $page = $q->paginate($this->perPage($request, 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (Client $c) => [
                'client' => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone],
                'tier' => $c->loyalty?->tier?->only(['id', 'name', 'key']),
                'points_balance' => (int) $c->loyalty?->points_balance,
                'points_12m' => (int) $c->loyalty?->points_12m,
                'lifetime_points' => (int) $c->loyalty?->lifetime_points,
                'member' => (bool) $c->loyalty?->joined_at,
                'member_number' => $c->loyalty?->joined_at ? LoyaltyService::memberNumber($c->id) : null,
                'joined_in_app' => $c->loyalty?->joined_at && $c->loyalty?->enrol_note === 'Joined in the app',
                'joined_at' => $c->loyalty?->joined_at?->toIso8601String(),
                'requested_at' => $c->loyalty?->requested_at?->toIso8601String(),
                'request_note' => $c->loyalty?->request_note,
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'join_requests' => ClientLoyalty::query()->whereNull('joined_at')->whereNotNull('requested_at')->count()],
        ]);
    }

    /** Decline a request to join made in the app (the client sees the reason). */
    public function declineRequest(Request $request, Client $client, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->loyalty->declineMembership($client, $request->user(), $data['reason']);

        return $this->client($request, $client, $checkout);
    }

    /** Client loyalty profile for the front desk: tier, balance, vouchers, gift cards, history. */
    public function client(Request $request, Client $client, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => [
            'client' => ['id' => $client->id, 'name' => $client->name, 'phone' => $client->phone],
            'rewards' => $this->loyalty->dashboard($client),
            'enrolled_by' => $client->loyalty?->enroller?->name,
            'join_request' => $client->loyalty?->requested_at && ! $client->loyalty?->joined_at
                ? ['at' => $client->loyalty->requested_at->toIso8601String(), 'note' => $client->loyalty->request_note] : null,
            'requests' => \App\Http\Resources\PointsRequestResource::collection($client->pointsRequests()->with('payment', 'handler')->latest('id')->limit(20)->get()),
            'wallet' => $checkout->wallet($client),
            'vouchers' => VoucherResource::collection($client->vouchers()->with('reward.service')->latest()->limit(30)->get()),
            'history' => PointsLedgerResource::collection($client->pointsLedger()->with('creator')->latest('id')->limit(50)->get()),
            'referrals' => Referral::query()->where('referrer_id', $client->id)->with('referred:id,name')->get()
                ->map(fn ($r) => ['name' => $r->referred?->name, 'status' => $r->status, 'completed_at' => $r->completed_at?->toIso8601String()]),
            // Bills the front desk can check out here (vouchers, points, gift card, then money).
            'open_bills' => $request->user()->hasPermission('payments.collect')
                ? \App\Http\Resources\PaymentResource::collection($client->payments()->whereIn('status', ['unpaid', 'pending', 'failed'])
                    ->where(fn ($q) => $q->whereNull('payable_type')->orWhere('payable_type', '!=', 'gift_card'))->latest()->get())
                : [],
        ]]);
    }

    /** Enrol an existing client. */
    public function join(Request $request, Client $client, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $this->loyalty->join($client, $request->user(), $data['note'] ?? null);

        return $this->client($request, $client, $checkout);
    }

    /**
     * Enrol a client who asked to join: an existing client (client_id) or a new one with their details. A new
     * client gets a record now; when they sign up in the app with the same phone they see their points.
     */
    public function enrol(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'required_without:client', 'exists:clients,id'],
            'client' => ['nullable', 'required_without:client_id', 'array'],
            'client.name' => ['required_with:client', 'string', 'max:120'],
            'client.phone' => ['required_with:client', 'string', 'max:20'],
            'client.email' => ['nullable', 'email', 'max:160'],
            'client.gender' => ['nullable', Rule::in(['Female', 'Male'])],
            'client.date_of_birth' => ['nullable', 'date', 'before:today'],
            'client.preferred_branch_id' => ['nullable', 'exists:branches,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $client = ! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : null;
        if (! $client) {
            $phone = Phone::normalize($data['client']['phone']);
            if (! $phone) {
                return response()->json(['message' => 'Enter a valid phone number.', 'errors' => ['client.phone' => ['Enter a valid phone number.']]], 422);
            }
            $client = Client::query()->where('phone', $phone)->first();
            if (! $client) {
                $fields = collect($data['client'])->only(['name', 'email', 'gender', 'date_of_birth', 'preferred_branch_id'])->filter(fn ($v) => $v !== null && $v !== '');
                $client = Client::create([...$fields->all(), 'phone' => $phone, 'source' => 'walk_in', 'created_by' => $request->user()->id,
                    'preferred_branch_id' => $fields['preferred_branch_id'] ?? $request->user()->staffProfile?->branch_id]);
            }
        }
        if ($this->loyalty->isMember($client)) {
            throw \App\Exceptions\BusinessRuleException::make('already_member', "{$client->name} is already a Lavish Rewards member.");
        }
        $this->loyalty->join($client, $request->user(), $data['note'] ?? null);

        return response()->json($this->client($request, $client, $checkout)->getData(true), 201);
    }

    /** Add (+) or remove (−) points by hand. Always with a reason; written to the audit log. */
    public function adjust(Request $request, Client $client, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'between:-100000,100000', 'not_in:0'],
            'reason' => ['required', 'string', 'min:4', 'max:255'],
        ]);
        $this->loyalty->adjust($client, $data['points'], $data['reason'], $request->user());

        return $this->client($request, $client, $checkout);
    }

    /** Redeem a reward for a client standing at the desk. */
    public function redeem(Request $request, Client $client, Reward $reward): JsonResponse
    {
        $voucher = $this->loyalty->redeem($client, $reward, $request->user());

        return (new VoucherResource($voucher->load('reward.service')))->response()->setStatusCode(201);
    }

    // ------------------------------------------------------------------ reports & audit

    /** Owner report. ?from=&to= (default: last 30 days). */
    public function reports(Request $request): JsonResponse
    {
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $s = LoyaltySetting::current();
        $between = fn ($q) => $q->whereBetween('created_at', [$from, $to]);

        $issued = (int) PointsLedger::query()->where($between)->where(fn ($q) => $q->where('type', 'earn')->orWhere(fn ($q) => $q->where('type', 'adjust')->where('points', '>', 0)))->sum('points');
        $redeemed = (int) -PointsLedger::query()->where($between)->where('type', 'redeem')->sum('points');
        $expired = (int) -PointsLedger::query()->where($between)->where('type', 'expire')->sum('points');
        $outstanding = (int) ClientLoyalty::query()->where('points_balance', '>', 0)->sum('points_balance');

        $activeCards = GiftCard::query()->where('status', 'active');
        $soldLedger = GiftCardLedger::query()->where($between)->where('type', 'issue');

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'members' => [
                'total' => ClientLoyalty::query()->whereNotNull('joined_at')->count(),
                'new' => ClientLoyalty::query()->whereBetween('joined_at', [$from, $to])->count(),
                'by_tier' => LoyaltyTier::ladder()->map(fn ($t) => ['tier' => $t->name, 'key' => $t->key, 'count' => ClientLoyalty::query()->where('tier_id', $t->id)->count()]),
            ],
            'points' => [
                'issued' => $issued, 'redeemed' => $redeemed, 'expired' => $expired,
                'outstanding' => $outstanding, 'outstanding_value_etb' => $s->pointsToEtb($outstanding),
            ],
            'vouchers' => [
                'issued' => RewardRedemption::query()->where($between)->count(),
                'used' => RewardRedemption::query()->whereBetween('used_at', [$from, $to])->count(),
                'discount_given' => (int) RewardRedemption::query()->whereBetween('used_at', [$from, $to])->sum('discount_applied'),
            ],
            'referrals' => [
                'new' => Referral::query()->where($between)->count(),
                'rewarded' => Referral::query()->where('status', 'rewarded')->whereBetween('completed_at', [$from, $to])->count(),
            ],
            'gift_cards' => [
                'sold_count' => (clone $soldLedger)->whereHas('giftCard', fn ($q) => $q->where('type', '!=', 'promo'))->count(),
                'sold_value' => (int) (clone $soldLedger)->whereHas('giftCard', fn ($q) => $q->where('type', '!=', 'promo'))->sum('amount'),
                'promo_value' => (int) (clone $soldLedger)->whereHas('giftCard', fn ($q) => $q->where('type', 'promo'))->sum('amount'),
                'redeemed_value' => (int) -GiftCardLedger::query()->where($between)->where('type', 'redeem')->sum('amount'),
                // Not revenue until spent: show it separately in the books.
                'liability' => (int) (clone $activeCards)->sum('balance'),
                'active_cards' => (clone $activeCards)->count(),
            ],
            'top_members' => ClientLoyalty::query()->with('client:id,name', 'tier:id,name')->orderByDesc('points_12m')->limit(5)->get()
                ->map(fn ($m) => ['name' => $m->client?->name, 'tier' => $m->tier?->name, 'points_12m' => $m->points_12m]),
        ]]);
    }

    /** Audit log of manual changes. ?entity=client|gift_card|reward|… */
    public function audit(Request $request): JsonResponse
    {
        $page = AuditLog::query()->with('actor:id,name')
            ->when($request->string('entity')->value(), fn ($q, $e) => $q->where('entity', $e))
            ->latest('id')->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => [
                'id' => $a->id, 'action' => $a->action, 'entity' => $a->entity, 'entity_id' => $a->entity_id, 'reason' => $a->reason,
                'before' => $a->before, 'after' => $a->after, 'by' => $a->actor?->name, 'created_at' => $a->created_at->toIso8601String(),
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
