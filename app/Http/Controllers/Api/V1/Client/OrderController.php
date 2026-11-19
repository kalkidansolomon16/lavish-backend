<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\OrderPaymentMethod;
use App\Enums\PrescriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\StockAlert;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The cart lives on the phone. Checkout sends the lines; the server re-prices them,
 * checks stock and creates the order. For online payment, then call
 * POST /client/payments/{order.payment.id}/checkout.
 */
class OrderController extends ClientController
{
    private const WITH = ['items.product', 'events', 'payment'];

    public function __construct(private OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection($this->client($request)->orders()->with(self::WITH)->latest()->get());
    }

    public function store(Request $request): OrderResource
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['required', Rule::in(OrderPaymentMethod::values())],
            'prescription_id' => ['nullable', 'integer'],
        ]);
        $client = $this->client($request);

        $rx = null;
        if (! empty($data['prescription_id'])) {
            $rx = Prescription::query()->where('client_id', $client->id)->find($data['prescription_id']);
            if (! $rx || $rx->status !== PrescriptionStatus::Sent) {
                throw BusinessRuleException::make('prescription_unavailable', 'That prescription can’t be ordered.');
            }
        }

        $order = $this->orders->place(
            $client,
            $data['items'],
            ['address' => $data['delivery_address'], 'note' => $data['delivery_note'] ?? null, 'phone' => $data['contact_phone'] ?? null],
            OrderPaymentMethod::from($data['payment_method']),
            $rx,
        );

        return new OrderResource($order->load(self::WITH));
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $this->own($request, $order);

        return new OrderResource($order->load(self::WITH));
    }

    /** "I received my order" — the client confirms the delivery reached them; e-commerce then confirms it. */
    public function received(Request $request, Order $order): OrderResource
    {
        $this->own($request, $order);

        return new OrderResource($this->orders->confirmReceived($order)->load(self::WITH));
    }

    /** "Notify me" on an out-of-stock product. */
    public function notifyMe(Request $request, Product $product): JsonResponse
    {
        StockAlert::firstOrCreate(['product_id' => $product->id, 'client_id' => $this->client($request)->id], ['notified_at' => null]);

        return response()->json(['message' => 'We’ll let you know when it’s back in stock.']);
    }
}
