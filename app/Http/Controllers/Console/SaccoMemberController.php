<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\MemberDocument;
use App\Models\MemberFee;
use App\Models\MemberVetting;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\ImportService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaccoMemberController extends Controller
{
    private const COLUMN_MAP = [
        'Name'             => 'name',
        'Email'            => 'email',
        'Phone'            => 'phone',
        'National ID'      => 'national_id',
        'KRA PIN'          => 'kra_pin',
        'M-Pesa Number'    => 'm_pesa_number',
        'Membership Class' => 'membership_class',
        'Joined Date'      => 'joined_at',
        'Notes'            => 'notes',
    ];
    public function index(Request $request): JsonResponse
    {
        $q = SaccoMember::query()
            ->withCount(['fees', 'documents'])
            ->selectRaw('sacco_members.*, (SELECT COALESCE(SUM(amount),0) FROM member_fees WHERE member_id = sacco_members.id) as total_fees_paid');

        $this->scopeQuery($q, $request);

        if ($search = $request->query('search')) {
            $q->where(function ($sub) use ($search) {
                $sub->where('name', 'ilike', "%{$search}%")
                    ->orWhere('membership_no', 'ilike', "%{$search}%")
                    ->orWhere('national_id', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($class = $request->query('membership_class')) {
            $q->where('membership_class', $class);
        }

        return response()->json($q->orderBy('created_at', 'desc')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'        => 'required|string|exists:agencies,agency_id',
            'membership_class' => 'required|in:class_a,class_b',
            'name'             => 'required|string|max:255',
            'phone'            => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:255',
            'national_id'      => 'nullable|string|max:50',
            'kra_pin'          => 'nullable|string|max:30',
            'm_pesa_number'    => 'nullable|string|max:30',
            'vehicle_owner_id' => 'nullable|integer|exists:vehicle_owners,id',
            'notes'            => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $data['membership_no'] = SaccoMember::generateMembershipNo($data['agency_id']);
        $data['status']        = 'pending_vetting';
        $data['voting_rights'] = $data['membership_class'] === 'class_a';
        $data['created_by']    = $request->user()->id;

        $member = SaccoMember::create($data);

        return response()->json($member, 201);
    }

    /** GET /members/me/fees — returns fee history for the authenticated member. */
    public function meeFees(Request $request): JsonResponse
    {
        $member = SaccoMember::where('user_id', $request->user()->id)->firstOrFail();
        return response()->json($member->fees()->orderBy('paid_at', 'desc')->get());
    }

    /** GET /members/me — returns the authenticated member's own record. */
    public function me(Request $request): JsonResponse
    {
        $member = SaccoMember::where('user_id', $request->user()->id)
            ->with(['vehicleOwner.vehicles', 'vettings.vetter:id,name', 'fees', 'documents'])
            ->firstOrFail();

        $member->total_fees_paid = $member->totalFeesPaid();

        return response()->json($member);
    }

    /** POST /members/{member}/account — creates a console user account for this member. */
    public function createAccount(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        abort_if($member->user_id !== null, 422, 'This member already has a linked account.');

        $data = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
        ]);

        $role = $member->membership_class === 'class_a' ? 'member_a' : 'member_b';

        $user = User::create([
            'name'     => $member->name,
            'email'    => $data['email'],
            'password' => Hash::make(Str::random(16)),
        ]);

        $user->assignRole($role);

        $member->update(['user_id' => $user->id]);

        return response()->json($member->fresh(['user:id,name,email']));
    }

    public function show(Request $request, SaccoMember $member): JsonResponse
    {
        // Members may only view their own record
        if ($request->user()->hasAnyRole(['member_a', 'member_b'])) {
            $ownId = SaccoMember::where('user_id', $request->user()->id)->value('id');
            abort_if($ownId !== $member->id, 403, 'You can only view your own membership record.');
        } else {
            $this->assertAgencyAllowed($request, $member->agency_id);
        }

        $member->load(['vehicleOwner.vehicles', 'vettings.vetter:id,name', 'fees', 'documents', 'user:id,name,email']);
        $member->total_fees_paid = $member->totalFeesPaid();

        return response()->json($member);
    }

    public function update(Request $request, SaccoMember $member): JsonResponse
    {
        if ($request->user()->hasAnyRole(['member_a', 'member_b'])) {
            $ownId = SaccoMember::where('user_id', $request->user()->id)->value('id');
            abort_if($ownId !== $member->id, 403);
        } else {
            $this->assertAgencyAllowed($request, $member->agency_id);
        }

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:255',
            'national_id'      => 'nullable|string|max:50',
            'kra_pin'          => 'nullable|string|max:30',
            'm_pesa_number'    => 'nullable|string|max:30',
            'vehicle_owner_id' => 'nullable|integer|exists:vehicle_owners,id',
            'notes'            => 'nullable|string',
        ]);

        $member->update($data);

        return response()->json($member->fresh());
    }

    public function vet(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $data = $request->validate([
            'decision' => 'required|in:approved,flagged',
            'notes'    => 'nullable|string',
        ]);

        $vetting = MemberVetting::create([
            'member_id'  => $member->id,
            'vetted_by'  => $request->user()->id,
            'decision'   => $data['decision'],
            'notes'      => $data['notes'] ?? null,
            'vetted_at'  => now(),
        ]);

        if ($data['decision'] === 'approved') {
            $member->update(['status' => 'approved']);
        }

        return response()->json($vetting->load('vetter:id,name'));
    }

    public function activate(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $hasApproval = $member->vettings()->where('decision', 'approved')->exists();
        if (! $hasApproval) {
            abort(422, 'Member must have at least one approved vetting decision before activation.');
        }

        $member->update([
            'status'    => 'active',
            'joined_at' => now()->toDateString(),
        ]);

        return response()->json($member->fresh());
    }

    public function suspend(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $data = $request->validate(['reason' => 'nullable|string']);

        $member->update([
            'status' => 'suspended',
            'notes'  => $data['reason'] ?? $member->notes,
        ]);

        return response()->json($member->fresh());
    }

    public function reinstate(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $member->update(['status' => 'active']);

        return response()->json($member->fresh());
    }

    public function fees(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        return response()->json($member->fees()->orderBy('paid_at', 'desc')->get());
    }

    public function recordFee(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $data = $request->validate([
            'fee_type'  => 'required|in:registration,share_capital,entrance_fee,monthly_dues,other',
            'amount'    => 'required|numeric|min:1',
            'paid_at'   => 'nullable|date',
            'method'    => 'required|in:mpesa,cash,bank_transfer',
            'mpesa_ref' => 'nullable|string|max:30',
            'notes'     => 'nullable|string',
        ]);

        $fee = MemberFee::create([
            'member_id'   => $member->id,
            'fee_type'    => $data['fee_type'],
            'amount'      => $data['amount'],
            'paid_at'     => $data['paid_at'] ?? now(),
            'method'      => $data['method'],
            'mpesa_ref'   => $data['mpesa_ref'] ?? null,
            'recorded_by' => $request->user()->id,
            'notes'       => $data['notes'] ?? null,
        ]);

        if ($data['fee_type'] === 'share_capital') {
            $member->increment('share_capital_paid', $data['amount']);
        }

        return response()->json($fee, 201);
    }

    public function uploadDocument(Request $request, SaccoMember $member): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        $request->validate([
            'file'     => 'required|file|max:4096',
            'doc_type' => 'required|in:national_id,kra_pin,logbook,insurance,inspection_cert,photo,other',
            'label'    => 'nullable|string|max:255',
        ]);

        $path = $request->file('file')->store("member-docs/{$member->id}", 'public');
        $url  = Storage::disk('public')->url($path);

        $doc = MemberDocument::create([
            'member_id'   => $member->id,
            'doc_type'    => $request->input('doc_type'),
            'label'       => $request->input('label'),
            'url'         => $url,
            'uploaded_by' => $request->user()->id,
            'created_at'  => now(),
        ]);

        return response()->json($doc, 201);
    }

    public function deleteDocument(Request $request, SaccoMember $member, MemberDocument $doc): JsonResponse
    {
        $this->assertAgencyAllowed($request, $member->agency_id);

        if ($doc->member_id !== $member->id) {
            abort(404);
        }

        $relative = str_replace(Storage::disk('public')->url(''), '', $doc->url);
        Storage::disk('public')->delete($relative);
        $doc->delete();

        return response()->json(null, 204);
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $q = SaccoMember::query();
        $this->scopeQuery($q, $request);

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $members = $q->orderBy('membership_no')->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="members-export.csv"',
        ];

        return response()->stream(function () use ($members) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Membership No', 'Name', 'Class', 'Status', 'Phone', 'Email', 'National ID', 'Joined At']);
            foreach ($members as $m) {
                fputcsv($handle, [
                    $m->membership_no,
                    $m->name,
                    $m->membership_class,
                    $m->status,
                    $m->phone,
                    $m->email,
                    $m->national_id,
                    $m->joined_at?->toDateString(),
                ]);
            }
            fclose($handle);
        }, 200, $headers);
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function importSample(): StreamedResponse
    {
        $service = app(ImportService::class);
        $content = $service->sampleCsvContent(
            array_keys(self::COLUMN_MAP),
            [
                // joined_at present → imported as active (historical member)
                ['Jane Kamau',   'jane@example.com', '0712345678', '12345678', 'A001234X', '0712345678', 'Class A', '2022-06-01', ''],
                // joined_at empty → imported as pending_vetting (new member)
                ['Peter Otieno', 'peter@example.com', '0723456789', '87654321', '', '0723456789', 'Class B', '', 'Associate member'],
            ]
        );

        return response()->stream(function () use ($content) {
            echo $content;
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="members-import-sample.csv"',
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        abort_if(! in_array($ext, ['csv', 'xlsx'], true), 422, 'Only CSV and XLSX files are supported.');

        $service = app(ImportService::class);
        $tempId  = $service->storeTempFile($request->file('file'));
        $result  = $service->preview($tempId, self::COLUMN_MAP, $this->memberRowValidator());

        return response()->json($result);
    }

    public function importConfirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'temp_id'   => 'required|string',
            'agency_id' => 'required|string|exists:agencies,agency_id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $service = app(ImportService::class);
        $userId  = $request->user()->id;
        $agencyId = $data['agency_id'];

        $result = $service->confirm(
            $data['temp_id'],
            self::COLUMN_MAP,
            $this->memberRowValidator(),
            function (array $row) use ($agencyId, $userId) {
                $classRaw = strtolower(preg_replace('/[\s_\-]/', '', $row['membership_class']));
                $class    = match (true) {
                    in_array($classRaw, ['classa', 'a'], true) => 'class_a',
                    in_array($classRaw, ['classb', 'b'], true) => 'class_b',
                    default                                     => $classRaw,
                };

                $joinedAt = null;
                if (! empty(trim($row['joined_at'] ?? ''))) {
                    $joinedAt = \Carbon\Carbon::parse($row['joined_at'])->toDateString();
                }

                SaccoMember::create([
                    'agency_id'        => $agencyId,
                    'membership_class' => $class,
                    'name'             => trim($row['name']),
                    'phone'            => $row['phone'] ?: null,
                    'email'            => $row['email'] ?: null,
                    'national_id'      => $row['national_id'] ?: null,
                    'kra_pin'          => $row['kra_pin'] ?: null,
                    'm_pesa_number'    => $row['m_pesa_number'] ?: null,
                    'notes'            => $row['notes'] ?: null,
                    'membership_no'    => SaccoMember::generateMembershipNo($agencyId),
                    'status'           => $joinedAt ? 'active' : 'pending_vetting',
                    'joined_at'        => $joinedAt,
                    'voting_rights'    => $class === 'class_a',
                    'created_by'       => $userId,
                ]);
            }
        );

        return response()->json($result);
    }

    private function memberRowValidator(): Closure
    {
        return function (array $row): array {
            $errors = [];

            if (empty(trim($row['name'] ?? ''))) {
                $errors[] = 'Name is required';
            }

            if (empty(trim($row['email'] ?? ''))) {
                $errors[] = 'Email is required';
            } elseif (! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is not valid';
            }

            $classRaw = strtolower(preg_replace('/[\s_\-]/', '', $row['membership_class'] ?? ''));
            if (! in_array($classRaw, ['classa', 'classb', 'a', 'b'], true)) {
                $errors[] = 'Membership Class must be "Class A" or "Class B"';
            }

            if (! empty($row['phone']) && ! preg_match('/^[0-9+\s\-]{7,20}$/', $row['phone'])) {
                $errors[] = 'Phone format is invalid';
            }

            if (! empty(trim($row['joined_at'] ?? ''))) {
                try {
                    $date = \Carbon\Carbon::parse($row['joined_at']);
                    if ($date->isFuture()) {
                        $errors[] = 'Joined Date cannot be in the future';
                    }
                } catch (\Throwable) {
                    $errors[] = 'Joined Date must be a valid date (e.g. 2022-06-01)';
                }
            }

            return $errors;
        };
    }
}
