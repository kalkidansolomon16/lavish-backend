<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\BookingSource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\DoctorResource;
use App\Models\Appointment;
use App\Models\ClassSession;
use App\Models\Doctor;
use App\Services\AppointmentService;
use App\Services\ScheduleService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppointmentController extends StaffController
{
    private const WITH = ['client', 'doctor.user', 'service', 'branch', 'payment', 'approver'];

    public function __construct(private AppointmentService $appointments, private ScheduleService $schedule) {}

    /**
     * Filters: status (pending|approved|declined|cancelled|completed), online=1, unassigned=1,
     *          doctor_id, client_id, from / to (Y-m-d, by start date), q (client name/phone), sort=asc|desc.
     * Doctors without approval rights only ever see their own appointments.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $this->doctorScope($request->user());
        $q = Appointment::query()->with(self::WITH)
            ->when($scope, fn ($q) => $q->where('doctor_id', $scope))
            ->when($request->string('status')->value(), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->boolean('online'), fn ($q) => $q->where('is_online', true))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('doctor_id'))
            ->when($request->integer('doctor_id'), fn ($q, $d) => $q->where('doctor_id', $d))
            ->when($request->integer('client_id'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($request->date('from'), fn ($q, $d) => $q->where('starts_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('starts_at', '<=', $d->endOfDay()))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->orderBy('starts_at', $request->string('sort')->value() === 'asc' ? 'asc' : 'desc');

        return AppointmentResource::collection($q->paginate($this->perPage($request, 50)));
    }

    /** Academy classes in a date range, for the shared calendar (?from&to[&doctor_id]). */
    public function classes(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'doctor_id' => ['nullable', 'integer']]);
        $scope = $this->doctorScope($request->user());
        $sessions = ClassSession::query()->with('course:id,title', 'teacher.user')
            ->where('starts_at', '>=', $request->date('from')->startOfDay())->where('starts_at', '<=', $request->date('to')->endOfDay())
            ->when($scope ?? ($data['doctor_id'] ?? null), fn ($q, $d) => $q->where('teacher_id', $d))
            ->orderBy('starts_at')->get();

        return response()->json(['data' => $sessions->map(fn ($s) => [
            'id' => $s->id, 'title' => $s->title, 'kind' => $s->kind, 'room' => $s->room,
            'course' => $s->course?->title, 'teacher' => ['id' => $s->teacher_id, 'name' => $s->teacher?->name],
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
        ])]);
    }

    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->authorizeView($request, $appointment);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    /**
     * Before approving: the doctor's clashes, their day plan, and ranked doctor suggestions
     * (for unassigned bookings or when the chosen doctor is busy).
     */
    public function check(Request $request, Appointment $appointment): JsonResponse
    {
        $appointment->load('client', 'service', 'doctor.user');
        $doctor = $appointment->doctor;

        // Other clients who asked for the same doctor at an overlapping time (approving this one makes theirs clash).
        $sameTime = $doctor ? \App\Models\Appointment::query()->with('client:id,name', 'service:id,name')->where('doctor_id', $doctor->id)
            ->where('status', 'pending')->whereKeyNot($appointment->id)->overlapping($appointment->starts_at, $appointment->ends_at)->get()
            ->map(fn ($a) => ['id' => $a->id, 'client' => $a->client?->name, 'service' => $a->service?->name, 'time' => $a->starts_at->format('H:i'), 'requested_at' => $a->created_at->toIso8601String()]) : collect();

        return response()->json(['data' => [
            'doctor' => $doctor ? ['id' => $doctor->id, 'name' => $doctor->name] : null,
            'slots' => $doctor ? $this->schedule->doctorDay($doctor, $appointment) : [],
            'same_time_requests' => $sameTime->values(),
            'free_times' => $doctor ? $this->schedule->freeTimes($appointment->service, $doctor, $appointment->starts_at->startOfDay()->max(now()->startOfDay())) : [],
            'change_request' => $appointment->change_request ? [...$appointment->change_request, 'at' => $appointment->change_requested_at?->toIso8601String()] : null,
            'clashes' => $doctor ? $this->schedule->clashes($doctor, $appointment->starts_at, $appointment->ends_at, $appointment->id) : [],
            'day_plan' => $doctor ? $this->schedule->dayPlan($doctor, $appointment->starts_at) : [],
            'suggestions' => collect($this->schedule->suggest($appointment))->map(fn ($s) => [
                'doctor' => new DoctorResource($s['doctor']),
                'score' => $s['score'],
                'available' => $s['available'],
                'reasons' => $s['reasons'],
            ]),
            'client_history' => AppointmentResource::collection(
                $appointment->client->appointments()->with('service', 'doctor.user')->where('status', 'completed')->latest('starts_at')->limit(5)->get()
            ),
        ]]);
    }

    /** Body: doctor_id (optional — required if the booking has no doctor yet). 422 doctor_busy with details.clashes. */
    public function approve(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate(['doctor_id' => ['nullable', 'exists:doctors,id'], 'allow_unpaid' => ['sometimes', 'boolean']]);
        $doctor = isset($data['doctor_id']) ? Doctor::with('user')->find($data['doctor_id']) : null;

        return new AppointmentResource($this->appointments->approve($appointment, $request->user(), $doctor, (bool) ($data['allow_unpaid'] ?? false))->load(self::WITH));
    }

    /**
     * The doctor is busy: ask the client to change. Body: kind time|doctor|either, message?, times[{date,time}]?, doctor_ids[]?
     * The client gets a notification + SMS and changes the booking in the app.
     */
    public function requestChange(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['time', 'doctor', 'either'])],
            'message' => ['nullable', 'string', 'max:300'],
            'times' => ['nullable', 'array', 'max:8'],
            'times.*.date' => ['required', 'date_format:Y-m-d'],
            'times.*.time' => ['required', 'date_format:H:i'],
            'doctor_ids' => ['nullable', 'array', 'max:6'],
            'doctor_ids.*' => ['integer', 'exists:doctors,id'],
        ]);

        return new AppointmentResource($this->appointments->requestChange($appointment, $request->user(), $data['kind'], $data['message'] ?? null, $data['times'] ?? [], $data['doctor_ids'] ?? [])->load(self::WITH));
    }

    public function decline(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new AppointmentResource($this->appointments->decline($appointment, $request->user(), $data['reason'] ?? null)->load(self::WITH));
    }

    public function complete(Request $request, Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($this->appointments->complete($appointment, $request->user())->load(self::WITH));
    }

    /**
     * Walk-in / phone booking (4 steps in the web: client → history → slot → confirm & SMS).
     * Either client_id (existing) or client{name, phone, gender, email, date_of_birth} (new).
     */
    public function walkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'required_without:client', 'exists:clients,id'],
            'client' => ['nullable', 'required_without:client_id', 'array'],
            'client.name' => ['required_with:client', 'string', 'max:120'],
            'client.phone' => ['required_with:client', 'string', 'max:20'],
            'client.gender' => ['nullable', Rule::in(['Female', 'Male'])],
            'client.email' => ['nullable', 'email'],
            'client.date_of_birth' => ['nullable', 'date', 'before:today'],
            'source' => ['nullable', Rule::in([BookingSource::WalkIn->value, BookingSource::PhoneCall->value, BookingSource::Referral->value])],
            'history' => ['nullable', 'array'],
            'history.source' => ['nullable', 'string', 'max:32'],
            'history.concern' => ['nullable', 'string', 'max:2000'],
            'history.skin_type' => ['nullable', 'string', 'max:32'],
            'history.allergies' => ['nullable', 'string', 'max:1000'],
            'history.medications' => ['nullable', 'string', 'max:1000'],
            'history.conditions' => ['nullable', 'string', 'max:1000'],
            'history.previous_treatments' => ['nullable', 'string', 'max:1000'],
            'history.pregnant' => ['nullable', 'string', 'max:24'],
            'service_id' => ['required', 'exists:services,id'],
            'doctor_id' => ['required', 'exists:doctors,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'price' => ['nullable', 'integer', 'min:0'],
            'send_sms' => ['boolean'],
            'send_app_invite' => ['boolean'],
            'allow_unpaid' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['client']['phone']) && ! Phone::normalize($data['client']['phone'])) {
            return response()->json(['message' => 'Enter a valid phone number.', 'errors' => ['client.phone' => ['Enter a valid phone number.']]], 422);
        }

        $result = $this->appointments->walkIn($data, $request->user());

        return response()->json(['data' => [
            'appointment' => new AppointmentResource($result['appointment']->load(self::WITH)),
            'client' => new ClientResource($result['client']->load('history', 'primaryDoctor.user')),
        ]], 201);
    }

    private function authorizeView(Request $request, Appointment $appointment): void
    {
        $scope = $this->doctorScope($request->user());
        abort_if($scope && $appointment->doctor_id !== $scope, 404);
    }
}
