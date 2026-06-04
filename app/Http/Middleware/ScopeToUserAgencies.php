<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeToUserAgencies
{
    /**
     * Injects the user's allowed agency IDs into the request so controllers
     * can apply row-level scoping without repeating the lookup.
     *
     * Superadmins and hopln_admin/hopln_staff get null (unrestricted).
     * All operator roles get their scoped agency list.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasAnyRole(['superadmin', 'hopln_admin', 'hopln_staff'])) {
            // Global access — no restriction
            $request->merge(['_agency_scope' => null]);
        } elseif ($user) {
            $request->merge([
                '_agency_scope' => $user->agencyScopes()->pluck('agency_id')->toArray(),
            ]);
        }

        return $next($request);
    }
}
