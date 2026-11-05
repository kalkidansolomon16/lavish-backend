<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;

abstract class StaffController extends Controller
{
    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min($request->integer('per_page', $default), 200));
    }

    /**
     * Null when the user may see every client/appointment (reception, managers);
     * otherwise the doctor id their view is limited to. Aborts if they are neither.
     */
    protected function doctorScope(User $user): ?int
    {
        if ($user->hasPermission('appointments.approve', 'clients.history')) {
            return null;
        }
        $doctorId = $user->doctor?->id;
        abort_unless($doctorId, 403, 'You don’t have permission to do that.');

        return $doctorId;
    }

    protected function myDoctor(Request $request): Doctor
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403, 'Only doctors can do this.');

        return $doctor;
    }
}
