<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\PaymentStatus;
use App\Http\Resources\ClientHistoryResource;
use App\Http\Resources\ClientResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientHistory;
use App\Services\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ClientController extends StaffController
{
    public function __construct(private Messenger $messenger) {}

    /**
     * Filters: q (name / phone / email), has_app=0|1, treated=0|1, doctor_id.
     * Doctors only see their own patients.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $this->doctorScope($request->user());

        $q = $this->query()
            ->when($scope, fn ($q) => $q->where(fn ($w) => $w->where('primary_doctor_id', $scope)->orWhereHas('appointments', fn ($a) => $a->where('doctor_id', $scope))))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', '%'.(ltrim(preg_replace('/\D/', '', $t), '0') ?: '#').'%')->orWhere('email', 'like', "%{$t}%")))
            ->when($request->has('has_app'), fn ($q) => $request->boolean('has_app') ? $q->whereNotNull('user_id') : $q->whereNull('user_id'))
            ->when($request->has('treated'), fn ($q) => $request->boolean('treated') ? $q->whereNotNull('first_treated_at') : $q->whereNull('first_treated_at'))
            ->when($request->integer('doctor_id'), fn ($q, $d) => $q->where('primary_doctor_id', $d))
            ->latest();

        return ClientResource::collection($q->paginate($this->perPage($request)));
    }

    /** Client drawer: profile, intake history, appointments, payments, orders, prescriptions. */
    public function show(Request $request, Client $client): ClientResource
    {
        $this->authorizeView($request, $client);
        $client = $this->query()->with([
            'history.recorder',
            'appointments' => fn ($q) => $q->with('service', 'doctor.user', 'branch', 'payment')->latest('starts_at'),
            'payments' => fn ($q) => $q->latest(),
            'orders' => fn ($q) => $q->with('items', 'payment')->latest(),
            'prescriptions' => fn ($q) => $q->with('doctor.user', 'items.product')->latest(),
        ])->findOrFail($client->id);

        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $this->authorizeView($request, $client);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email'],
            'gender' => ['sometimes', 'nullable', Rule::in(['Female', 'Male'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'preferred_branch_id' => ['sometimes', 'nullable', 'exists:branches,id'],
            'primary_doctor_id' => ['sometimes', 'nullable', 'exists:doctors,id'],
        ]);
        $client->update($data);

        return $this->show($request, $client);
    }

    public function updateHistory(Request $request, Client $client): ClientHistoryResource
    {
        $data = $request->validate(collect(ClientHistory::FIELDS)->mapWithKeys(fn ($f) => [$f => ['nullable', 'string', 'max:2000']])->all());
        $history = ClientHistory::updateOrCreate(['client_id' => $client->id], [...$data, 'recorded_by' => $request->user()->id]);

        return new ClientHistoryResource($history->load('recorder'));
    }

    /** SMS with the app download link. */
    public function invite(Request $request, Client $client): JsonResponse
    {
        if ($client->hasApp()) {
            return response()->json(['message' => "{$client->name} already uses the app.", 'code' => 'already_on_app'], 422);
        }
        $first = strtok($client->name, ' ');
        $this->messenger->sms($client->phone, "Lavish Medical Spa: Hi {$first}, get the Lavish app to book, pay and chat with your doctor: ".config('lavish.app_download_url').' — sign up with this phone number.', $client);
        $client->update(['app_invited_at' => now()]);

        return response()->json(['message' => "App download link sent to {$client->phone}."]);
    }

    private function query()
    {
        return Client::query()->with('primaryDoctor.user', 'preferredBranch', 'history.recorder')
            ->addSelect(['last_treatment' => Appointment::query()->join('services', 'services.id', '=', 'appointments.service_id')
                ->whereColumn('appointments.client_id', 'clients.id')->where('appointments.status', 'completed')
                ->latest('appointments.starts_at')->limit(1)->select('services.name')])
            ->withSum(['payments as total_spent' => fn ($p) => $p->where('status', PaymentStatus::Paid)], 'amount');
    }

    private function authorizeView(Request $request, Client $client): void
    {
        $scope = $this->doctorScope($request->user());
        abort_if($scope && $client->primary_doctor_id !== $scope && ! $client->appointments()->where('doctor_id', $scope)->exists(), 404);
    }
}
