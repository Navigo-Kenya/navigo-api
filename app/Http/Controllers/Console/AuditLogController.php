<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AuditLog::with('user:id,name,avatar')->orderByDesc('created_at');

        if ($request->filled('agency_id')) {
            $q->where('agency_id', $request->input('agency_id'));
        } else {
            $scope = $this->agencyScope($request);
            if ($scope !== null) {
                $q->whereIn('agency_id', $scope);
            }
        }

        if ($request->filled('action')) {
            $q->where('action', 'like', $request->input('action').'%');
        }

        if ($request->filled('user_id')) {
            $q->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->input('to'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }
}
