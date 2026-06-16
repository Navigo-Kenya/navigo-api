<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $all = Permission::all();

        // ── superadmin ──────────────────────────────────────────────────────────
        // Gets every permission; wildcard is handled in User::getEffectivePermissions()
        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions($all);

        // ── hopln_admin ─────────────────────────────────────────────────────────
        $hopln_admin = Role::firstOrCreate(['name' => 'hopln_admin', 'guard_name' => 'web']);
        $hopln_admin->syncPermissions($all);

        // ── hopln_staff ─────────────────────────────────────────────────────────
        $hopln_staff = Role::firstOrCreate(['name' => 'hopln_staff', 'guard_name' => 'web']);
        $hopln_staff->syncPermissions([
            'dashboard.view',
            'users.view',
            'contributions.view', 'contributions.moderate',
            'stops.view', 'routes.view', 'trips.view', 'calendars.view', 'agencies.view',
            'network.view',
            'fares.view',
            'analytics.view',
            'quality.view', 'quality.fix',
            'gtfs.view',
            'ops.view',
            'settings.view',
            'access.view',
        ]);

        // ── operator_owner ──────────────────────────────────────────────────────
        $operator_owner = Role::firstOrCreate(['name' => 'operator_owner', 'guard_name' => 'web']);
        $operator_owner->syncPermissions([
            'dashboard.view',
            'stops.view', 'stops.create', 'stops.edit', 'stops.delete',
            'routes.view', 'routes.create', 'routes.edit', 'routes.delete',
            'trips.view', 'trips.create', 'trips.edit', 'trips.delete',
            'calendars.view', 'calendars.create', 'calendars.edit', 'calendars.delete',
            'agencies.edit',                                    // own agency settings only; no agencies.view (list)
            'fares.view', 'fares.edit',
            'fleet.view', 'fleet.edit',
            'ledger.view', 'ledger.configure',
            'ops.view', 'ops.manage_alerts', 'ops.manage_incidents',
            'scheduling.view', 'scheduling.edit',
        ]);

        // ── operator_data_manager ────────────────────────────────────────────────
        $operator_dm = Role::firstOrCreate(['name' => 'operator_data_manager', 'guard_name' => 'web']);
        $operator_dm->syncPermissions([
            'dashboard.view',
            'stops.view', 'stops.create', 'stops.edit', 'stops.delete',
            'routes.view', 'routes.create', 'routes.edit', 'routes.delete',
            'trips.view', 'trips.create', 'trips.edit', 'trips.delete',
            'calendars.view', 'calendars.create', 'calendars.edit', 'calendars.delete',
            'network.view',
            'scheduling.view', 'scheduling.edit',
        ]);

        // ── operator_fleet_manager ───────────────────────────────────────────────
        $operator_fm = Role::firstOrCreate(['name' => 'operator_fleet_manager', 'guard_name' => 'web']);
        $operator_fm->syncPermissions([
            'dashboard.view',
            'fleet.view', 'fleet.edit',
            'ops.view', 'ops.manage_incidents',
            'scheduling.view',
        ]);

        // ── operator_finance_officer ─────────────────────────────────────────────
        $operator_fo = Role::firstOrCreate(['name' => 'operator_finance_officer', 'guard_name' => 'web']);
        $operator_fo->syncPermissions([
            'dashboard.view',
            'fares.view', 'fares.edit',
            'ledger.view', 'ledger.configure',
        ]);

        // ── operator_ops_coordinator ─────────────────────────────────────────────
        $operator_oc = Role::firstOrCreate(['name' => 'operator_ops_coordinator', 'guard_name' => 'web']);
        $operator_oc->syncPermissions([
            'dashboard.view',
            'ops.view', 'ops.manage_alerts', 'ops.manage_incidents',
            'fleet.view',
            'scheduling.view',
        ]);

        // ── moderator ────────────────────────────────────────────────────────────
        $moderator = Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
        $moderator->syncPermissions([
            'dashboard.view',
            'users.view',
            'contributions.view', 'contributions.moderate',
            'stops.view', 'routes.view',
            'ops.view', 'ops.manage_incidents',
        ]);

        // ── custom ───────────────────────────────────────────────────────────────
        // No permissions by default — everything is hand-assigned per user
        Role::firstOrCreate(['name' => 'custom', 'guard_name' => 'web']);
    }
}
