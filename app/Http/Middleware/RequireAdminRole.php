<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    /**
     * Enforce console access and optional permission check.
     *
     * Usage examples:
     *   ->middleware('role')                    console access only (any console role)
     *   ->middleware('role:gtfs.sync')          requires specific permission
     *   ->middleware('role:stops.create,stops.edit')  requires ALL listed permissions
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasConsoleAccess()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->isBanned()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        if (!empty($permissions)) {
            $effective = $user->getEffectivePermissions();
            $isWildcard = \in_array('*', $effective, true);

            foreach ($permissions as $permission) {
                if (!$isWildcard && !\in_array($permission, $effective, true)) {
                    return response()->json(['message' => 'Insufficient permissions.'], 403);
                }
            }
        }

        return $next($request);
    }
}
