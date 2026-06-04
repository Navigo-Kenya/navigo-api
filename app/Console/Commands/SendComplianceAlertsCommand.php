<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendComplianceAlertsCommand extends Command
{
    protected $signature   = 'compliance:send-alerts {--dry-run : Log alerts without sending notifications}';
    protected $description = 'Send compliance expiry alerts to agency owners and fleet managers';

    private const WARN_DAYS = [30, 7, 1];

    public function handle(): int
    {
        $today    = now()->startOfDay();
        $agencies = Agency::all();

        foreach ($agencies as $agency) {
            $alerts = $this->buildAlerts($agency->agency_id, $today);

            if (empty($alerts)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[dry-run] {$agency->agency_name}: " . count($alerts) . ' expiry alert(s)');
                continue;
            }

            $this->notifyAgencyStaff($agency->agency_id, $alerts);
        }

        return self::SUCCESS;
    }

    private function buildAlerts(string $agencyId, \Carbon\Carbon $today): array
    {
        $alerts = [];

        $vehicleFields = [
            'insurance_expiry'            => 'PSV Insurance',
            'inspection_due'              => 'NTSA Inspection',
            'road_service_license_expiry' => 'Road Service License',
            'speed_limiter_cert_expiry'   => 'Speed Limiter Certificate',
        ];

        $driverFields = [
            'psv_badge_expiry'    => 'PSV Badge',
            'licence_expiry'      => 'Driving Licence',
            'good_conduct_expiry' => 'Certificate of Good Conduct',
            'medical_cert_expiry' => 'Medical Certificate',
        ];

        foreach (Vehicle::where('agency_id', $agencyId)->get() as $vehicle) {
            foreach ($vehicleFields as $field => $label) {
                if (!$vehicle->$field) continue;
                $daysLeft = (int) $today->diffInDays($vehicle->$field, false);
                if (in_array($daysLeft, self::WARN_DAYS, true) || $daysLeft < 0) {
                    $alerts[] = [
                        'type'      => 'vehicle',
                        'entity'    => $vehicle->plate,
                        'document'  => $label,
                        'days_left' => $daysLeft,
                    ];
                }
            }
        }

        foreach (
            Driver::whereHas('vehicle', fn ($v) => $v->where('agency_id', $agencyId))->get()
            as $driver
        ) {
            foreach ($driverFields as $field => $label) {
                if (!$driver->$field) continue;
                $daysLeft = (int) $today->diffInDays($driver->$field, false);
                if (in_array($daysLeft, self::WARN_DAYS, true) || $daysLeft < 0) {
                    $alerts[] = [
                        'type'      => 'driver',
                        'entity'    => $driver->name,
                        'document'  => $label,
                        'days_left' => $daysLeft,
                    ];
                }
            }
        }

        return $alerts;
    }

    private function notifyAgencyStaff(string $agencyId, array $alerts): void
    {
        $recipientRoles = ['operator_owner', 'operator_fleet_manager'];

        $users = User::whereHas('agencyScopes', fn ($s) => $s->where('agency_id', $agencyId))
            ->whereHas('roles', fn ($r) => $r->whereIn('name', $recipientRoles))
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $summary = collect($alerts)
            ->map(fn ($a) => ($a['days_left'] < 0 ? '[EXPIRED] ' : "[{$a['days_left']}d] ") . "{$a['entity']} — {$a['document']}")
            ->join("\n");

        DB::table('notifications')->insert([
            'id'              => \Illuminate\Support\Str::uuid(),
            'type'            => 'compliance_alert',
            'notifiable_type' => 'App\\Models\\Agency',
            'notifiable_id'   => 0,
            'data'            => json_encode([
                'agency_id' => $agencyId,
                'title'     => 'Compliance Expiry Alert',
                'body'      => count($alerts) . ' document(s) expiring or expired.',
                'alerts'    => $alerts,
            ]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        Log::info("compliance:send-alerts [{$agencyId}] " . count($alerts) . " alert(s) for " . $users->count() . " recipient(s)");
    }
}
