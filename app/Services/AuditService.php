<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function log(
        string  $action,
        ?Model  $subject = null,
        array   $before  = [],
        array   $after   = [],
        ?string $agencyId = null
    ): void {
        $user = Auth::user();

        AuditLog::create([
            'agency_id'    => $agencyId ?? ($user?->_agency_scope[0] ?? null),
            'user_id'      => $user?->id,
            'actor_name'   => $user?->name,
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'before_json'  => empty($before) ? null : $before,
            'after_json'   => empty($after)  ? null : $after,
            'ip_address'   => Request::ip(),
        ]);
    }
}
