<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\PaymentResource;
use App\Http\Resources\PointsLedgerResource;
use App\Http\Resources\PointsRequestResource;
use App\Models\Payment;
use App\Models\PointsRequest;
use App\Services\PointsRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Members' requests to use points (web: Lavish Rewards → Points requests, and Orders → Points for e-commerce).
 *   rewards.requests — reception: every request; take points off treatment bills, send shop requests on, reject
 *   orders.points    — e-commerce: shop requests sent to them; take the points off the client's shop order
 */
class PointsRequestController extends StaffController
{
    public function __construct(private PointsRequestService $service) {}

    /** ?status=open|pending|forwarded|completed|rejected|cancelled (default open) &kind= &q= (client name / phone) */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reception = $request->user()->hasPermission('rewards.requests');
        $status = $request->string('status')->value() ?: 'open';
        $q = PointsRequest::query()->with('client.loyalty', 'payment', 'forwarder', 'handler')
            ->when(! $reception, fn ($q) => $q->where('kind', 'shop')->whereNotNull('forwarded_at'))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', $reception ? PointsRequest::OPEN : ['forwarded']), fn ($q) => $q->where('status', $status))
            ->when($request->string('kind')->value(), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")
                ->orWhere('phone', 'like', '%'.(ltrim(preg_replace('/\D/', '', $t), '0') ?: '#').'%')))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'forwarded' THEN 1 ELSE 2 END")->latest('id');

        $open = PointsRequest::query()->when(! $reception, fn ($q) => $q->where('status', 'forwarded'), fn ($q) => $q->whereIn('status', PointsRequest::OPEN));

        return PointsRequestResource::collection($q->paginate($this->perPage($request, 20)))->additional(['meta' => [
            'open' => (clone $open)->count(),
            'pending' => $reception ? PointsRequest::query()->where('status', 'pending')->count() : 0,
            'forwarded' => PointsRequest::query()->where('status', 'forwarded')->count(),
        ]]);
    }

    /** One request with the client's unpaid bills to take the points off and their recent points history. */
    public function show(Request $request, PointsRequest $pointsRequest): JsonResponse
    {
        $this->authorizeView($request, $pointsRequest);
        $client = $pointsRequest->client;
        $bills = $client->payments()->whereIn('status', ['unpaid', 'pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('payable_type')->orWhere('payable_type', '!=', 'gift_card'))
            // e-commerce sees shop orders; reception sees treatments (and orders for "other" if needed)
            ->when($pointsRequest->kind === 'shop', fn ($q) => $q->where('payable_type', 'order'))
            ->when($pointsRequest->kind === 'treatment', fn ($q) => $q->where('payable_type', 'appointment'))
            ->latest()->get();

        return response()->json(['data' => [
            'request' => new PointsRequestResource($pointsRequest->load('client.loyalty', 'payment', 'forwarder', 'handler')),
            'bills' => PaymentResource::collection($bills),
            'history' => PointsLedgerResource::collection($client->pointsLedger()->with('creator')->latest('id')->limit(15)->get()),
            'point_value_etb' => (float) \App\Models\LoyaltySetting::current()->point_value_etb,
        ]]);
    }

    public function forward(Request $request, PointsRequest $pointsRequest): PointsRequestResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return new PointsRequestResource($this->service->forward($pointsRequest, $request->user(), $data['note'] ?? null)->load('client.loyalty', 'payment', 'forwarder', 'handler'));
    }

    /** { payment_id } to take the points off a bill, or { note } saying how they were used. */
    public function complete(Request $request, PointsRequest $pointsRequest): PointsRequestResource
    {
        $this->authorizeView($request, $pointsRequest);
        $data = $request->validate([
            'payment_id' => ['nullable', Rule::exists('payments', 'id')->where('client_id', $pointsRequest->client_id)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $payment = ! empty($data['payment_id']) ? Payment::find($data['payment_id']) : null;
        // E-commerce only takes points off shop orders.
        abort_if(! $request->user()->hasPermission('rewards.requests') && $payment && $payment->payable_type !== 'order', 403, 'Choose one of the client’s shop orders.');

        return new PointsRequestResource($this->service->complete($pointsRequest, $request->user(), $payment, $data['note'] ?? null)->load('client.loyalty', 'payment', 'forwarder', 'handler'));
    }

    public function reject(Request $request, PointsRequest $pointsRequest): PointsRequestResource
    {
        $this->authorizeView($request, $pointsRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new PointsRequestResource($this->service->reject($pointsRequest, $request->user(), $data['reason'])->load('client.loyalty', 'payment', 'forwarder', 'handler'));
    }

    /** E-commerce only sees shop requests that reception sent to them. */
    private function authorizeView(Request $request, PointsRequest $r): void
    {
        if (! $request->user()->hasPermission('rewards.requests')) {
            abort_unless($r->kind === 'shop' && $r->forwarded_at, 404);
        }
    }
}
