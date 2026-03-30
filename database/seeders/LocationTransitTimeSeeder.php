<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationTransitTimeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('location_transit_time')->insert([
            [
                'origin_factory_id' => 1,
                'destination_factory_id' => 2,
                'transit_time' => 10,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'origin_factory_id' => 2,
                'destination_factory_id' => 3,
                'transit_time' => 8,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'origin_factory_id' => 1,
                'destination_factory_id' => 3,
                'transit_time' => 15,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
