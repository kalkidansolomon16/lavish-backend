<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\DoctorAccess;
use App\Enums\DoctorAvailability;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Resources\DoctorResource;
use App\Http\Resources\StaffMemberResource;
use App\Models\Doctor;
use App\Models\User;
use App\Services\StaffService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Staff & access (CEO) / Staff · HR (General Manager). */
class StaffMemberController extends StaffController
{
    private const WITH = ['roles', 'staffProfile.branch', 'doctor.services', 'doctor.courses', 'doctor.user.staffProfile.branch'];

    public function __construct(private StaffService $staff) {}

    /** Filters: role (key), status, q. meta.creatable_roles = roles you may add. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $members = User::query()->where('type', UserType::Staff)->with(self::WITH)
            ->when($request->string('role')->value(), fn ($q, $r) => $q->whereHas('roles', fn ($x) => $x->where('key', $r)))
            ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->orderBy('name')->get()
            ->sortBy(fn (User $u) => $u->level())->values();

        return StaffMemberResource::collection($members)->additional(['meta' => [
            'creatable_roles' => $this->staff->creatableRoles($request->user()),
            'pending_doctors' => Doctor::query()->where('access', DoctorAccess::Pending)->count(),
        ]]);
    }

    /**
     * Add a person and give them access. Returns the temporary password ONCE (also sent by SMS).
     * For role=doctor, "position" is the specialty and service_ids are the treatments they give.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(StaffService::CREATABLE)],
            'position' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);
        if (! Phone::normalize($data['phone'])) {
            return response()->json(['message' => 'Enter a valid phone number.', 'errors' => ['phone' => ['Enter a valid phone number.']]], 422);
        }
        $result = $this->staff->create($request->user(), $data);

        return response()->json(['data' => [
            'member' => new StaffMemberResource($result['user']->load(self::WITH)),
            'temporary_password' => $result['password'],
        ]], 201);
    }

    /** Body: status=active|suspended. */
    public function setStatus(Request $request, User $member): StaffMemberResource
    {
        $data = $request->validate(['status' => ['required', Rule::in([UserStatus::Active->value, UserStatus::Suspended->value])]]);

        return new StaffMemberResource($this->staff->setStatus($request->user(), $member->load('roles'), UserStatus::from($data['status']))->load(self::WITH));
    }

    public function resetPassword(Request $request, User $member): JsonResponse
    {
        return response()->json(['data' => ['temporary_password' => $this->staff->resetPassword($request->user(), $member->load('roles'))]]);
    }

    /** Body: access=active|banned|pending. */
    public function setDoctorAccess(Request $request, Doctor $doctor): DoctorResource
    {
        $data = $request->validate(['access' => ['required', Rule::in(DoctorAccess::values())]]);

        return new DoctorResource($this->staff->setDoctorAccess($request->user(), $doctor->load('user.roles'), DoctorAccess::from($data['access']))->load('user.staffProfile.branch', 'services', 'courses'));
    }

    /**
     * Doctor profile shown in the app. Managers (doctors.grant) can edit any doctor;
     * a doctor can edit their own availability and bio.
     */
    public function updateDoctor(Request $request, Doctor $doctor): DoctorResource
    {
        $user = $request->user();
        $manager = $user->hasPermission('doctors.grant');
        abort_unless($manager || $doctor->user_id === $user->id, 403);

        $data = $request->validate([
            'availability' => ['sometimes', Rule::in(DoctorAvailability::values())],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'working_days' => ['sometimes', 'nullable', 'string', 'max:64'],
            'specialty' => [$manager ? 'sometimes' : 'prohibited', 'string', 'max:120'],
            'years_experience' => [$manager ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'min:0', 'max:70'],
            'service_ids' => [$manager ? 'sometimes' : 'prohibited', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'branch_id' => [$manager ? 'sometimes' : 'prohibited', 'nullable', 'exists:branches,id'],
        ]);
        $doctor->update(collect($data)->except('service_ids', 'branch_id')->all());
        if (array_key_exists('service_ids', $data)) {
            $doctor->services()->sync($data['service_ids']);
        }
        if (array_key_exists('branch_id', $data)) {
            $doctor->user->staffProfile()->updateOrCreate([], ['branch_id' => $data['branch_id']]);
        }

        return new DoctorResource($doctor->load('user.staffProfile.branch', 'services', 'courses'));
    }

    /** Edit position / branch of a staff member below you. */
    public function update(Request $request, User $member): StaffMemberResource
    {
        abort_unless($request->user()->hasPermission('staff.add') && $member->load('roles')->level() > $request->user()->level(), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($member->id)],
            'position' => ['sometimes', 'nullable', 'string', 'max:120'],
            'branch_id' => ['sometimes', 'nullable', 'exists:branches,id'],
        ]);
        $member->update(collect($data)->only('name', 'email')->all());
        $member->staffProfile()->updateOrCreate([], collect($data)->only('position', 'branch_id')->all());

        return new StaffMemberResource($member->load(self::WITH));
    }
}
