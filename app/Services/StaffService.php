<?php

namespace App\Services;

use App\Enums\DoctorAccess;
use App\Enums\DoctorAvailability;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Support\Phone;
use App\Support\TempPassword;
use Illuminate\Support\Facades\DB;

/**
 * Staff accounts and doctor access. Hierarchy: you can only add, suspend or reactivate
 * people whose role is below yours, and never yourself.
 */
class StaffService
{
    /** Roles a staff member can be created with from the Staff page. "teacher" comes from academy teacher access. */
    public const CREATABLE = ['doctor', 'gm', 'reception', 'ecommerce', 'team'];

    public function __construct(private Messenger $messenger, private Notifier $notifier) {}

    /** @return list<string> role keys $actor may create */
    public function creatableRoles(User $actor): array
    {
        if (! $actor->hasPermission('staff.add')) {
            return [];
        }

        return Role::query()->whereIn('key', self::CREATABLE)->where('level', '>', $actor->level())->pluck('key')->all();
    }

    /** @return array{user: User, password: string} */
    public function create(User $actor, array $data): array
    {
        if (! in_array($data['role'], $this->creatableRoles($actor), true)) {
            throw BusinessRuleException::make('cannot_create_role', 'You can’t add people to that role.', status: 403);
        }
        $phone = Phone::normalize($data['phone']);
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            throw BusinessRuleException::make('phone_in_use', 'Someone already uses this phone number.');
        }

        $password = TempPassword::make();
        $user = DB::transaction(function () use ($actor, $data, $phone, $password) {
            $isDoctor = $data['role'] === 'doctor';
            $name = $isDoctor && ! str_starts_with($data['name'], 'Dr.') ? 'Dr. '.$data['name'] : $data['name'];
            $user = User::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'type' => UserType::Staff,
                'status' => UserStatus::Active,
                'password' => $password,
                'must_change_password' => true,
                'phone_verified_at' => now(),
            ]);
            $user->roles()->attach(Role::query()->where('key', $data['role'])->value('id'));
            $user->staffProfile()->create([
                'position' => $isDoctor ? 'Doctor' : ($data['position'] ?? null),
                'branch_id' => $data['branch_id'] ?? null,
                'created_by' => $actor->id,
            ]);
            if ($isDoctor) {
                $doctor = $user->doctor()->create([
                    'specialty' => $data['position'] ?? $data['specialty'] ?? 'General',
                    'access' => $actor->hasPermission('doctors.grant') ? DoctorAccess::Active : DoctorAccess::Pending,
                    'availability' => DoctorAvailability::Available,
                ]);
                if (! empty($data['service_ids'])) {
                    $doctor->services()->sync($data['service_ids']);
                }
            }

            return $user;
        });

        $this->messenger->sms($phone, "Lavish staff portal: Hi {$user->name}, your account is ready. Sign in with {$phone} and temporary password {$password} — you’ll choose your own password at first login.", $user, 'Staff portal invite [temporary password hidden]');

        if ($user->doctor?->access === DoctorAccess::Pending) {
            $this->notifier->staff('doctors.grant', new LavishNotification('doctor.pending', 'Doctor waiting for access',
                "{$user->name} is waiting for portal access.", 'badge', null, '/staff'), $actor);
        }

        return ['user' => $user->load('roles', 'staffProfile.branch', 'doctor'), 'password' => $password];
    }

    public function setStatus(User $actor, User $member, UserStatus $status): User
    {
        $this->assertBelow($actor, $member);
        $needed = $status === UserStatus::Suspended ? 'staff.suspend' : 'staff.add';
        if (! $actor->hasPermission($needed)) {
            throw BusinessRuleException::make('forbidden', 'You don’t have permission to do that.', status: 403);
        }
        $member->update(['status' => $status]);
        if ($status === UserStatus::Suspended) {
            $member->tokens()->delete();
        }

        return $member;
    }

    public function setDoctorAccess(User $actor, Doctor $doctor, DoctorAccess $access): Doctor
    {
        $this->assertBelow($actor, $doctor->user);
        $needed = ($access === DoctorAccess::Banned || $doctor->access === DoctorAccess::Banned) ? 'doctors.ban' : 'doctors.grant';
        if (! $actor->hasPermission($needed)) {
            throw BusinessRuleException::make('forbidden', 'You don’t have permission to do that.', status: 403);
        }
        $doctor->update(['access' => $access]);
        if ($access !== DoctorAccess::Active) {
            $doctor->user->tokens()->delete();
        }

        return $doctor;
    }

    /** Issues a new temporary password for a staff member below the actor. */
    public function resetPassword(User $actor, User $member): string
    {
        $this->assertBelow($actor, $member);
        if (! $actor->hasPermission('staff.add')) {
            throw BusinessRuleException::make('forbidden', 'You don’t have permission to do that.', status: 403);
        }
        $password = TempPassword::make();
        $member->update(['password' => $password, 'must_change_password' => true]);
        $member->tokens()->delete();
        $this->messenger->sms($member->phone, "Lavish staff portal: your new temporary password is {$password}.", $member, 'Staff password reset [temporary password hidden]');

        return $password;
    }

    private function assertBelow(User $actor, User $member): void
    {
        if ($actor->is($member) || $member->level() <= $actor->level()) {
            throw BusinessRuleException::make('hierarchy', 'You can only manage people below you.', status: 403);
        }
    }
}
