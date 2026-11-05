<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** permission:appointments.approve — or several alternatives: permission:page.orders|orders.deliver */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        if (! $request->user()?->hasPermission(...explode('|', $permissions))) {
            return response()->json(['message' => 'You don’t have permission to do that.', 'code' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
