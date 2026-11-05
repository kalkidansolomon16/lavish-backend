<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * type:staff  → web portal routes;  type:client / type:student → mobile app routes.
 * Also locks out accounts that were suspended (or doctors whose access was removed) after the token was issued.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->type->value, $types, true)) {
            return response()->json(['message' => 'This account can’t use this part of Lavish.', 'code' => 'wrong_account_type'], 403);
        }
        if (! $user->canSignIn()) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Your account is not active. Please contact Lavish.', 'code' => 'account_inactive'], 403);
        }

        return $next($request);
    }
}
