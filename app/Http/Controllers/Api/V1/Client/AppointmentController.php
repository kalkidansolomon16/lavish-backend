<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\AppointmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends ClientController
{
    private const WITH = ['service', 'doctor.user', 'branch', 'payment', 'review'];

    public function __construct(private AppointmentService $appointments) {}

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
        ]);
        $appt = $this->appointments->book($this->client($request), $data);

        return new AppointmentResource($appt->load(self::WITH));
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

        return new AppointmentResource($appointment->load(self::WITH));
    }
}
