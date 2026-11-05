<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaymentController extends StaffController
{
    public function __construct(private PaymentService $payments) {}

    /**
     * Filters: status (comma list), type=appointment|order, method, client_id, q (client), from / to (created date), online=1.
     * meta has totals for the filtered set.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Payment::query()->with('client', 'recorder', 'payable')
            ->when($request->string('status')->value(), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->string('type')->value(), fn ($q, $t) => $q->where('payable_type', $t))
            ->when($request->string('method')->value(), fn ($q, $m) => $q->where('method', $m))
            ->when($request->integer('client_id'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($request->boolean('online'), fn ($q) => $q->whereHasMorph('payable', ['appointment'], fn ($a) => $a->where('is_online', true)))
            ->when($request->date('from'), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")));

        $totals = (clone $q)->toBase()->reorder()
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as paid', [PaymentStatus::Paid->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN amount ELSE 0 END) as outstanding', [PaymentStatus::Unpaid->value, PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as refund_due', [PaymentStatus::RefundDue->value])
            ->first();

        return PaymentResource::collection($q->latest()->paginate($this->perPage($request, 50)))
            ->additional(['meta' => ['totals' => [
                'paid' => (int) $totals->paid, 'outstanding' => (int) $totals->outstanding, 'refund_due' => (int) $totals->refund_due,
            ]]]);
    }

    /** Record a payment taken at the desk. Body: method (cash|telebirr|cbe_birr|card|bank_transfer). */
    public function record(Request $request, Payment $payment): PaymentResource
    {
        $data = $request->validate(['method' => ['required', Rule::in(PaymentMethod::values())]]);

        return new PaymentResource($this->payments->recordManual($payment, PaymentMethod::from($data['method']), $request->user())->load('client', 'recorder'));
    }

    public function refund(Request $request, Payment $payment): PaymentResource
    {
        return new PaymentResource($this->payments->markRefunded($payment, $request->user())->load('client', 'recorder'));
    }

    /** Re-checks a pending Chapa payment (when a client says they paid). */
    public function verify(Payment $payment): JsonResponse
    {
        $payment = $this->payments->confirm($payment->load('client'));

        return response()->json(['data' => new PaymentResource($payment)]);
    }
}
