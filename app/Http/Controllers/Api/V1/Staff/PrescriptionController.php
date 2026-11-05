<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\OrderResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Client;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\OrderService;
use App\Services\PrescriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrescriptionController extends StaffController
{
    public function __construct(private PrescriptionService $prescriptions, private OrderService $orders) {}

    /**
     * Doctors see their own; E-commerce / managers see all.
     * Filters: status=sent|ordered, client_id, waiting=1 (has an out-of-stock item not yet re-suggested).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $mineOnly = ! $user->hasPermission('prescriptions.fulfil') && $user->doctor;

        $q = Prescription::query()->with(['client', 'doctor.user', 'items.product' => fn ($p) => $p->withStock()])
            ->when($mineOnly, fn ($q) => $q->where('doctor_id', $user->doctor->id))
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('client_id'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($request->boolean('waiting'), fn ($q) => $q->whereHas('items', fn ($i) => $i->where('was_out_of_stock', true)->whereNull('suggested_at')))
            ->latest();

        return PrescriptionResource::collection($q->paginate($this->perPage($request, 50)));
    }

    /**
     * Doctors only. Items: product_id (Lavish product) OR custom_name (outside medicine), quantity, instructions.
     */
    public function store(Request $request): PrescriptionResource
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['nullable', 'required_without:items.*.custom_name', 'exists:products,id'],
            'items.*.custom_name' => ['nullable', 'required_without:items.*.product_id', 'string', 'max:160'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.instructions' => ['nullable', 'string', 'max:255'],
        ]);
        $rx = $this->prescriptions->create($this->myDoctor($request), Client::findOrFail($data['client_id']), $data['note'] ?? null, $data['items']);

        return new PrescriptionResource($rx->load(['client', 'doctor.user', 'items.product' => fn ($p) => $p->withStock()]));
    }

    /** The doctor tells the client a waiting product is back (message in their chat). */
    public function suggest(Request $request, PrescriptionItem $item): PrescriptionResource
    {
        $this->prescriptions->suggestRestocked($item->load('prescription.client', 'product'), $this->myDoctor($request));

        return new PrescriptionResource($item->prescription->load(['client', 'doctor.user', 'items.product' => fn ($p) => $p->withStock()]));
    }

    /** E-commerce: turn the Lavish products into an order (pay on delivery, address confirmed by phone). */
    public function fulfil(Request $request, Prescription $prescription): OrderResource
    {
        $order = $this->orders->fromPrescription($prescription->load('items', 'client'), $request->user());

        return new OrderResource($order->load('client', 'items.product', 'events.user', 'payment', 'emails'));
    }
}
