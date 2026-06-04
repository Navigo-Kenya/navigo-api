<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // RBAC — must run before AdminSeeder so the superadmin role exists
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminSeeder::class,
            BadgeSeeder::class,

            AgencySeeder::class,
            ServiceCalendarSeeder::class,
            // GtfsSeeder::class,
            GtfsExcelSeeder::class,
        ]);

        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        //     'phone_number' => '+254745908026'
        // ]);
    }
}
