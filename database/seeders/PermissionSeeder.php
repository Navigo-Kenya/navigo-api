<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Dashboard
            'dashboard.view',

            // Users
            'users.view',
            'users.edit',
            'users.ban',
            'users.assign_role',

            // Community contributions
            'contributions.view',
            'contributions.moderate',

            // GTFS data
            'stops.view',
            'stops.create',
            'stops.edit',
            'stops.delete',

            'routes.view',
            'routes.create',
            'routes.edit',
            'routes.delete',

            'trips.view',
            'trips.create',
            'trips.edit',
            'trips.delete',

            'calendars.view',
            'calendars.create',
            'calendars.edit',
            'calendars.delete',

            'agencies.view',
            'agencies.create',
            'agencies.edit',
            'agencies.delete',

            // Network planning
            'network.view',
            'network.edit',
            'network.publish_scenario',

            // Fares
            'fares.view',
            'fares.edit',

            // Fleet
            'fleet.view',
            'fleet.edit',

            // Financial ledger
            'ledger.view',
            'ledger.configure',

            // Real-time operations
            'ops.view',
            'ops.manage_alerts',
            'ops.manage_incidents',

            // Scheduling
            'scheduling.view',
            'scheduling.edit',

            // GTFS export & sync
            'gtfs.view',
            'gtfs.export',
            'gtfs.sync',

            // Analytics & reach
            'analytics.view',
            'notifications.send',

            // Data quality
            'quality.view',
            'quality.fix',

            // Interoperability
            'interop.view',
            'interop.edit',

            // System settings
            'settings.view',
            'settings.edit',

            // RBAC management (this section)
            'access.view',
            'access.manage',
            'access.impersonate', // switch into any operator_owner account (superadmin / hopln_admin only)

            // SACCO members (management — operator roles)
            'members.view',
            'members.manage',

            // Member self-service (member_a / member_b roles)
            'profile.view',       // access own membership profile
            'fleet.view.own',     // Class A: view own vehicles only
            'ledger.view.own',    // Class A: view own vehicle earnings only
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
