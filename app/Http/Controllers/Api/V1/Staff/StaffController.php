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
     * Null when the user may see every client/appointment (reception, managers, nurses);
     * otherwise the doctor id a doctor's view is limited to.
     */
    protected function doctorScope(User $user): ?int
    {
        // Reception and managers see everyone; a doctor sees their own patients; other clinical staff
        // (nurses) see everyone, since the route already checked they may open the page.
        if ($user->hasPermission('appointments.approve', 'appointments.create', 'clients.history')) {
            return null;
        }

        return $user->doctor?->id;
    }

    protected function myDoctor(Request $request): Doctor
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403, 'Only doctors can do this.');

        return $doctor;
    }
}
