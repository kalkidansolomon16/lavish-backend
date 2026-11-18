<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\AppointmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Services\CheckoutService;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends ClientController
{
    private const WITH = ['service', 'doctor.user', 'branch', 'payment', 'review'];

    public function __construct(
        private AppointmentService $appointments,
        private LoyaltyService $loyalty,
        private CheckoutService $checkout,
    ) {}

    /**
     * tab=upcoming  approved, not yet started
     * tab=requests  pending
     * tab=past      completed, declined, cancelled, or approved in the past
     * (no tab = everything, newest first)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->client($request)->appointments()->with(self::WITH);

        match ($request->string('tab')->value()) {
            'upcoming' => $q->where('status', AppointmentStatus::Approved)->where('ends_at', '>=', now())->orderBy('starts_at'),
            'requests' => $q->where('status', AppointmentStatus::Pending)->orderBy('starts_at'),
            'past' => $q->where(fn ($w) => $w->whereIn('status', ['completed', 'declined', 'cancelled'])
                ->orWhere(fn ($x) => $x->where('status', 'approved')->where('ends_at', '<', now())))->orderByDesc('starts_at'),
            default => $q->orderByDesc('starts_at'),
        };

        return AppointmentResource::collection($q->get());
    }

    /**
     * Book a treatment or an online consultation.
     * Body: service_id, date (Y-m-d), time (HH:mm from /catalog/config booking_slots),
     *       doctor_id (optional — omit to let reception choose), branch_id (required for clinic visits without a doctor), note
     */
    public function store(Request $request): AppointmentResource
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'doctor_id' => ['nullable', 'exists:doctors,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
            'voucher_id' => ['nullable', 'integer'],
            'gift_card_id' => ['nullable', 'integer'],
        ]);
        $appt = $this->appointments->book($this->client($request), $data);

        // Vouchers / gift cards chosen on the booking summary are applied to the new bill.
        // If one can't be used, the booking still stands and the reason is returned in meta.credits_error.
        $error = null;
        if (! empty($data['voucher_id']) || ! empty($data['gift_card_id'])) {
            try {
                $this->checkout->apply($appt->payment, array_filter(['voucher_id' => $data['voucher_id'] ?? null, 'gift_card_id' => $data['gift_card_id'] ?? null]));
            } catch (BusinessRuleException $e) {
                $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return (new AppointmentResource($appt->refresh()->load(self::WITH)))->additional(['meta' => ['credits_error' => $error]]);
    }

    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->own($request, $appointment);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->own($request, $appointment);

        return new AppointmentResource($this->appointments->cancelByClient($appointment)->load(self::WITH));
    }

    /** Rate the doctor after a completed treatment (once). */
    public function review(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->own($request, $appointment);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($appointment->status !== AppointmentStatus::Completed || $appointment->review()->exists()) {
            throw BusinessRuleException::make('cannot_review', 'You can review a treatment once, after it is completed.');
        }
        $appointment->review()->create([...$data, 'doctor_id' => $appointment->doctor_id, 'client_id' => $appointment->client_id]);
        $this->loyalty->creditReview($appointment->load('client'));

        return new AppointmentResource($appointment->load(self::WITH));
    }
}
