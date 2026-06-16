<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AgencySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('agencies')->upsert(
            [
                [
                    'agency_id'       => 'navigo',
                    'agency_name'     => 'Navigo Nairobi',
                    'type'            => 'authority', // Transit authority overseeing multiple operators
                    'agency_url'      => 'https://navigo.co.ke',
                    'agency_timezone' => 'Africa/Nairobi',
                    'agency_lang'     => 'en',
                    'agency_phone'    => null,
                    'agency_email'    => 'support@navigo.co.ke',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ],
            ['agency_id'],
            ['agency_name', 'type', 'agency_url', 'agency_timezone', 'updated_at']
        );
    }
}
