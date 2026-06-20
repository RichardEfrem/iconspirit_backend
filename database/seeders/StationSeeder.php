<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StationSeeder extends Seeder
{
    public function run(): void
    {
        $stations = [
            ['id' => 1, 'nama_station' => 'kayu', 'factory_location_id' => 1, 'biaya_harian' => 50000],
            ['id' => 2, 'nama_station' => 'cat', 'factory_location_id' => 1, 'biaya_harian' => 35000],
            ['id' => 3, 'nama_station' => 'acc', 'factory_location_id' => 1, 'biaya_harian' => 20000],
        ];

        foreach ($stations as $station) {
            DB::table('station')->insert(array_merge($station, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
