<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@hopln.app')],
            [
                'name'             => 'Hopln Admin',
                'password'         => Hash::make(env('ADMIN_PASSWORD', 'changeme123!')),
                'role'             => 'superadmin',
                'phone_verified_at' => now(),
            ]
        );

        // Assign the Spatie superadmin role (syncs permissions automatically)
        $user->syncRoles(['superadmin']);

        $this->command->info('Superadmin created: ' . env('ADMIN_EMAIL', 'admin@hopln.app'));
        $this->command->warn('Change the password immediately: ADMIN_PASSWORD in .env');
    }
}
