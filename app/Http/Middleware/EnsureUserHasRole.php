<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict route access to users with specific roles.
 *
 * Available roles: admin, operator, inventory, owner.
 *
 * Usage in routes:
 *   ->middleware('role:admin')
 *   ->middleware('role:admin,operator')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You do not have the required role.',
            ], 403);
        }

        return $next($request);
    }
}
