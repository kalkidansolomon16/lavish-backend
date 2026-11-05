<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends StaffController
{
    private const WITH = ['client', 'items.product', 'events.user', 'payment', 'emails'];

    public function __construct(private OrderService $orders, private PaymentService $payments) {}

    /** Filters: status (comma list, or "open"), payment=paid|unpaid, q (order no., client, email, phone). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->value();
        $q = Order::query()->with(self::WITH)
            ->when($status === 'open', fn ($q) => $q->whereIn('status', OrderStatus::openValues()))
            ->when($status && $status !== 'open', fn ($q) => $q->whereIn('status', explode(',', $status)))
            ->when($request->string('payment')->value() === 'paid', fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('status', 'paid')))
            ->when($request->string('payment')->value() === 'unpaid', fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('status', '!=', 'paid')))
            ->when($request->string('q')->trim()->value(), function ($q, $t) {
                $id = (int) preg_replace('/\D/', '', $t);
                $q->where(fn ($w) => $w->when($id, fn ($x) => $x->orWhere('id', $id))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")));
            })
            ->latest();

        return OrderResource::collection($q->paginate($this->perPage($request, 50)));
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(self::WITH));
    }

    /**
     * Moves the order one step (placed → confirmed → preparing → ready → out → delivered).
     * Body for the "out" step: courier.name (required), courier.phone.
     * Response meta.low_stock lists products now at or below their reorder level.
     */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['courier' => ['nullable', 'array'], 'courier.name' => ['nullable', 'string', 'max:120'], 'courier.phone' => ['nullable', 'string', 'max:20']]);
        $low = $this->orders->advance($order, $request->user(), $data['courier'] ?? null);

        return response()->json([
            'data' => new OrderResource($order->fresh()->load(self::WITH)),
            'meta' => ['low_stock' => ProductResource::collection(collect($low)->map(fn ($p) => Product::query()->withStock()->find($p->id)))],
        ]);
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new OrderResource($this->orders->cancel($order, $request->user(), $data['reason'] ?? null)->load(self::WITH));
    }

    /** Body: method (cash|telebirr|cbe_birr|card|bank_transfer). */
    public function markPaid(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['method' => ['required', Rule::in(PaymentMethod::values())]]);
        $this->payments->recordManual($order->payment, PaymentMethod::from($data['method']), $request->user());

        return new OrderResource($order->fresh()->load(self::WITH));
    }

    public function resendEmail(Order $order): JsonResponse
    {
        $sent = $this->orders->resendLatestEmail($order);

        return response()->json(['message' => $sent ? "Email re-sent to {$sent->to}." : 'No email to resend.']);
    }
}
