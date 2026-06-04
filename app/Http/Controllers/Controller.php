<?php

namespace App\Http\Controllers;

use App\Jobs\OtpSyncJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

abstract class Controller
{
    /**
     * Returns the agency scope array injected by ScopeToUserAgencies middleware.
     * null = unrestricted (superadmin / hopln_admin / hopln_staff).
     * [] or [id,...] = operator with specific agency scope.
     */
    protected function agencyScope(Request $request): ?array
    {
        return $request->input('_agency_scope');
    }

    /**
     * Applies a whereIn agency filter when the user is operator-scoped.
     * Pass $column if the agency FK column isn't named 'agency_id'.
     */
    protected function scopeQuery(Builder $q, Request $request, string $column = 'agency_id'): Builder
    {
        $scope = $this->agencyScope($request);
        if ($scope !== null) {
            $q->whereIn($column, $scope);
        }
        return $q;
    }

    /**
     * Verify a given agency_id is allowed for the authenticated operator.
     * Global roles (scope === null) are always allowed.
     */
    protected function assertAgencyAllowed(Request $request, ?string $agencyId): void
    {
        $scope = $this->agencyScope($request);
        if ($scope !== null && !in_array($agencyId, $scope, true)) {
            abort(403, 'Agency not in your scope.');
        }
    }

    /**
     * Debounced OTP sync dispatch.
     *
     * Uses Cache::add (atomic) to ensure only one sync job is queued in any
     * $delaySecs window. Bulk operations that each call this method will result
     * in a single sync running $delaySecs after the first change in the burst.
     *
     * Pass $force=true to bypass the debounce (e.g. explicit "Sync now" button).
     */
    protected function scheduleOtpSync(int $delaySecs = 30, bool $force = false): void
    {
        if ($force) {
            Cache::forget('otp:sync_debounce');
        }

        // Cache::add is atomic: returns true only if key did not already exist.
        if (Cache::add('otp:sync_debounce', true, $delaySecs)) {
            OtpSyncJob::dispatch()->delay(now()->addSeconds($delaySecs))->onQueue('otp');
        }
    }
}
