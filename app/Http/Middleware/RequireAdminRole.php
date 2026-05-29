<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    /**
     * Allowed roles are passed as middleware parameters, e.g.
     *   ->middleware('role:admin,superadmin')
     * Defaults to moderator+ if no parameters given.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasConsoleAccess()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->isBanned()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        if (!empty($roles) && !in_array($user->role, $roles)) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        return $next($request);
    }
}
