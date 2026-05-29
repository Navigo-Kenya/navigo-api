<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $calendars = [
            [
                'service_id' => 'daily',
                'name'       => 'Daily',
                'monday'     => true, 'tuesday' => true, 'wednesday' => true,
                'thursday'   => true, 'friday'  => true,
                'saturday'   => true, 'sunday'  => true,
            ],
            [
                'service_id' => 'weekdays',
                'name'       => 'Weekdays',
                'monday'     => true, 'tuesday' => true, 'wednesday' => true,
                'thursday'   => true, 'friday'  => true,
                'saturday'   => false, 'sunday' => false,
            ],
            [
                'service_id' => 'weekends',
                'name'       => 'Weekends',
                'monday'     => false, 'tuesday' => false, 'wednesday' => false,
                'thursday'   => false, 'friday'  => false,
                'saturday'   => true,  'sunday'  => true,
            ],
            [
                'service_id' => 'school_days',
                'name'       => 'School Days',
                'monday'     => true, 'tuesday' => true, 'wednesday' => true,
                'thursday'   => true, 'friday'  => true,
                'saturday'   => false, 'sunday' => false,
            ],
            [
                'service_id' => 'default',
                'name'       => 'Default',
                'monday'     => true, 'tuesday' => true, 'wednesday' => true,
                'thursday'   => true, 'friday'  => true,
                'saturday'   => true, 'sunday'  => true,
            ],
        ];

        foreach ($calendars as &$row) {
            $row['start_date'] = '2026-01-01';
            $row['end_date']   = '2027-12-31';
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        DB::table('service_calendars')->upsert(
            $calendars,
            ['service_id'],
            ['name', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date', 'updated_at']
        );
    }
}
