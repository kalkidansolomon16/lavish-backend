<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created with a temporary password (new staff, students) must set their own
 * before using anything else. The app should show the "Set password" screen on this code.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json(['message' => 'Please choose a new password first.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
