<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Order all items" in the app: add the Lavish products (items with product != null and in_stock)
 * to the cart, and send prescription_id with the checkout so it is marked ordered.
 */
class PrescriptionController extends ClientController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PrescriptionResource::collection(
            $this->client($request)->prescriptions()->with(['doctor.user', 'items.product' => fn ($q) => $q->withStock()])->latest()->get()
        );
    }

    public function show(Request $request, Prescription $prescription): PrescriptionResource
    {
        $this->own($request, $prescription);

        return new PrescriptionResource($prescription->load(['doctor.user', 'items.product' => fn ($q) => $q->withStock()]));
    }
}
