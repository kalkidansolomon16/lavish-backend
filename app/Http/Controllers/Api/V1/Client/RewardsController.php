<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\PointsLedgerResource;
use App\Http\Resources\PointsRequestResource;
use App\Http\Resources\RewardResource;
use App\Http\Resources\VoucherResource;
use App\Models\LoyaltyTier;
use App\Models\PointsRequest;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\Service;
use App\Services\LoyaltyService;
use App\Services\PointsRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Lavish Rewards in the client app (Rewards tab).
 * Points are only ever credited by the server — the app just reads them.
 */
class RewardsController extends ClientController
{
    public function __construct(private LoyaltyService $loyalty) {}

    /** Rewards dashboard: balance, tier, progress to the next reward and tier, expiring points. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->loyalty->dashboard($this->client($request))]);
    }

    /** Ask to join Lavish Rewards. Reception enrols the client (or declines); body: { note? }. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $client = $this->client($request);
        $this->loyalty->requestMembership($client, $data['note'] ?? null);

        return response()->json(['data' => $this->loyalty->dashboard($client), 'message' => 'Request sent. Lavish will enrol you and let you know.'], 201);
    }

    /** My requests to use points, newest first. */
    public function requests(Request $request): AnonymousResourceCollection
    {
        return PointsRequestResource::collection($this->client($request)->pointsRequests()->with('payment')->latest('id')->limit(50)->get());
    }

    /** Use my points: what for (shop | treatment | other), how many, and a note for reception. */
    public function requestPoints(Request $request, PointsRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(PointsRequest::KINDS)],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'required_if:kind,other', 'string', 'max:500'],
        ]);
        $req = $service->create($this->client($request), $data['kind'], $data['points'], $data['note'] ?? null);

        return (new PointsRequestResource($req))->response()->setStatusCode(201);
    }

    /** Take back a request reception hasn't started on. */
    public function cancelRequest(Request $request, PointsRequest $pointsRequest, PointsRequestService $service): PointsRequestResource
    {
        abort_unless($pointsRequest->client_id === $this->client($request)->id, 404);

        return new PointsRequestResource($service->cancel($pointsRequest));
    }

    /** The tier-up screen was shown; don't show it again. */
    public function tierSeen(Request $request): JsonResponse
    {
        $this->loyalty->membership($this->client($request))->update(['tier_up_seen' => true]);

        return response()->json(['message' => 'OK']);
    }

    public function waysToEarn(): JsonResponse
    {
        return response()->json(['data' => $this->loyalty->waysToEarn()]);
    }

    /** Reward catalog with `affordable` and `locked` (tier-only rewards) for this client. */
    public function catalog(Request $request): AnonymousResourceCollection
    {
        $m = $this->loyalty->membership($this->client($request));
        $tierMin = (int) LoyaltyTier::find($m->tier_id)?->min_points_12m;
        $rewards = Reward::query()->available()->with('service', 'product', 'tierRequired')->orderBy('points_cost')->get()
            ->each(fn ($r) => $r->setAttribute('_balance', $m->points_balance)->setAttribute('_tier_min', $tierMin));

        return RewardResource::collection($rewards)->additional(['meta' => ['points_balance' => $m->points_balance]]);
    }

    /** Spend points on a reward → voucher (201). */
    public function redeem(Request $request, Reward $reward): JsonResponse
    {
        $voucher = $this->loyalty->redeem($this->client($request), $reward);

        return (new VoucherResource($voucher->load('reward.service')))->response()->setStatusCode(201);
    }

    /** My Vouchers. ?status=issued|used|expired (default: all, newest first). */
    public function vouchers(Request $request): AnonymousResourceCollection
    {
        $vouchers = $this->client($request)->vouchers()->with('reward.service')
            ->when($request->string('status')->value(), function ($q, $s) {
                return match ($s) {
                    'issued' => $q->where('status', 'issued')->where('expires_at', '>', now()),
                    'expired' => $q->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($q) => $q->where('status', 'issued')->where('expires_at', '<=', now()))),
                    default => $q->where('status', $s),
                };
            })
            ->latest()->get();

        return VoucherResource::collection($vouchers);
    }

    /** Points history: every earn, spend and expiry with its reason. */
    public function history(Request $request): AnonymousResourceCollection
    {
        return PointsLedgerResource::collection($this->client($request)->pointsLedger()->latest('id')->paginate(min($request->integer('per_page', 30), 100)));
    }

    /** Refer a friend: my code, share text and the friends I referred. */
    public function referrals(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $m = $this->loyalty->membership($client);
        $rule = \App\Models\EarnRule::general('referral');
        $list = Referral::query()->where('referrer_id', $client->id)->with('referred')->latest()->get();

        return response()->json(['data' => [
            'code' => $m->referral_code,
            'points_per_referral' => (int) $rule?->points,
            'max_per_year' => $this->loyalty->settings()->max_referrals_per_year,
            'share_text' => "Join me at Lavish Medical Spa! Use my code {$m->referral_code} when you sign up in the Lavish app: ".config('lavish.app_download_url'),
            'referrals' => $list->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->referred?->name, 'status' => $r->status,
                'joined_at' => $r->created_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            ]),
        ]]);
    }

    /** A new client enters a friend's code (if they didn't at sign-up). */
    public function useReferral(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $this->loyalty->applyReferralCode($this->client($request), $data['code']);

        return response()->json(['message' => 'Referral code saved. Your friend earns points after your first visit.'], 201);
    }

    /** Points this client would earn for a treatment (booking summary). ?service_id=&amount= */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate(['service_id' => ['required', 'exists:services,id'], 'amount' => ['nullable', 'integer', 'min:0']]);
        $service = Service::findOrFail($data['service_id']);

        return response()->json(['data' => [
            'service_id' => $service->id,
            'points' => $this->loyalty->estimate($this->client($request), $service, $data['amount'] ?? null),
        ]]);
    }
}
