<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\CheckoutService;
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
        $q = Payment::query()->with('client', 'recorder', 'payable', 'receipts.attachments', 'receipts.uploader', 'receipts.reviewer')
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
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as to_check', [PaymentStatus::InReview->value])
            ->first();

        return PaymentResource::collection($q->latest()->paginate($this->perPage($request, 50)))
            ->additional(['meta' => ['totals' => [
                'paid' => (int) $totals->paid, 'outstanding' => (int) $totals->outstanding, 'refund_due' => (int) $totals->refund_due, 'receipts_to_check' => (int) $totals->to_check,
            ]]]);
    }

    /**
     * Record a payment taken at the desk (or by transfer). Multipart allowed:
     * method (cash|telebirr|cbe_birr|card|bank_transfer), reference?, receipt? (image/PDF ≤ 5 MB).
     */
    public function record(Request $request, Payment $payment): PaymentResource
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(PaymentMethod::desk())],
            'reference' => ['nullable', 'string', 'max:120'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $payment = $this->payments->recordManual($payment, PaymentMethod::from($data['method']), $request->user(), $data['reference'] ?? null, $request->file('receipt'));

        return new PaymentResource($payment->load('client', 'recorder', 'receipts.attachments', 'receipts.uploader', 'receipts.reviewer'));
    }

    /** Accept a receipt the client uploaded → the bill is paid. */
    public function acceptReceipt(Request $request, Payment $payment, PaymentReceipt $receipt): PaymentResource
    {
        abort_unless($receipt->payment_id === $payment->id, 404);
        $payment = $this->payments->acceptReceipt($receipt, $request->user());

        return new PaymentResource($payment->load('client', 'recorder', 'receipts.attachments', 'receipts.uploader', 'receipts.reviewer'));
    }

    /** Reject a receipt (reason is sent to the client) → the bill is open again. */
    public function rejectReceipt(Request $request, Payment $payment, PaymentReceipt $receipt): PaymentResource
    {
        abort_unless($receipt->payment_id === $payment->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:255']]);
        $payment = $this->payments->rejectReceipt($receipt, $request->user(), $data['reason']);

        return new PaymentResource($payment->load('client', 'recorder', 'receipts.attachments', 'receipts.uploader', 'receipts.reviewer'));
    }

    public function refund(Request $request, Payment $payment): PaymentResource
    {
        return new PaymentResource($this->payments->markRefunded($payment, $request->user())->load('client', 'recorder'));
    }

    /** What this client can use on the bill: points, vouchers and gift cards. */
    public function wallet(Payment $payment, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->wallet($payment->client, $payment->load('payable'))]);
    }

    /**
     * Preview the split: voucher_id|voucher_code, points, gift_card_id|gift_card_code.
     * Order: tier price & voucher/points → gift card → money.
     */
    public function quote(Request $request, Payment $payment, CheckoutService $checkout): JsonResponse
    {
        return response()->json(['data' => $checkout->quote($payment, $this->checkoutOptions($request))]);
    }

    /** Apply credits and take the rest now (method). If nothing is left, the bill is closed without money. */
    public function checkout(Request $request, Payment $payment, CheckoutService $checkout): PaymentResource
    {
        $opts = $this->checkoutOptions($request);
        $method = $request->validate(['method' => ['nullable', Rule::in(PaymentMethod::desk())]])['method'] ?? null;
        $payment = $checkout->apply($payment, $opts, $request->user(), $method ? PaymentMethod::from($method) : null);

        return new PaymentResource($payment->refresh()->load('client', 'recorder'));
    }

    private function checkoutOptions(Request $request): array
    {
        return array_filter($request->validate([
            'voucher_id' => ['nullable', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:16'],
            'points' => ['nullable', 'integer', 'min:0'],
            'gift_card_id' => ['nullable', 'integer'],
            'gift_card_code' => ['nullable', 'string', 'max:32'],
        ]));
    }

    /** Re-checks a pending Chapa payment (when a client says they paid). */
    public function verify(Payment $payment): JsonResponse
    {
        $payment = $this->payments->confirm($payment->load('client'));

        return response()->json(['data' => new PaymentResource($payment)]);
    }
}
