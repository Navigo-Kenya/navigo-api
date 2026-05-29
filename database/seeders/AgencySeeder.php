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
                    'agency_id'       => 'hopln',
                    'agency_name'     => 'Hopln Nairobi',
                    'agency_url'      => 'https://hopln.app',
                    'agency_timezone' => 'Africa/Nairobi',
                    'agency_lang'     => 'en',
                    'agency_phone'    => null,
                    'agency_email'    => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ],
            ['agency_id'],
            ['agency_name', 'agency_url', 'agency_timezone', 'updated_at']
        );
    }
}
