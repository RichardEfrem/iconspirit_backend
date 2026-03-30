<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stations = [
            // Location ID 1
            ['nama_station' => 'kayu', 'factory_location_id' => 1, 'biaya_harian' => 50000],
            ['nama_station' => 'cat', 'factory_location_id' => 1, 'biaya_harian' => 35000],
            ['nama_station' => 'acc', 'factory_location_id' => 1, 'biaya_harian' => 20000],

            // Location ID 2
            ['nama_station' => 'kayu', 'factory_location_id' => 2, 'biaya_harian' => 55000],
            ['nama_station' => 'cat', 'factory_location_id' => 2, 'biaya_harian' => 40000],

            // Location ID 3
            ['nama_station' => 'kayu', 'factory_location_id' => 3, 'biaya_harian' => 52000],
            ['nama_station' => 'cat', 'factory_location_id' => 3, 'biaya_harian' => 38000],
        ];

        foreach ($stations as $station) {
            DB::table('station')->insert(array_merge($station, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
