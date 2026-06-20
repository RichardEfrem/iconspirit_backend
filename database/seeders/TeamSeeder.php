<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Faker\Factory as Faker;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $teams = [];
        $faker = Faker::create('id_ID');

        // Pabrik A Setup: 6 Kayu, 6 Cat, 3 Acc
        $setup = [
            ['station_id' => 1, 'count' => 6, 'suffix' => 'KA'],
            ['station_id' => 2, 'count' => 6, 'suffix' => 'CAT'],
            ['station_id' => 3, 'count' => 3, 'suffix' => 'ACC'],
        ];

        $usedNames = [];

        foreach ($setup as $config) {
            for ($i = 1; $i <= $config['count']; $i++) {
                do {
                    $firstName = strtoupper($faker->firstName);
                    $kodeTeam = $firstName . '-' . $config['suffix'];
                } while (in_array($kodeTeam, $usedNames));

                $usedNames[] = $kodeTeam;
                $teams[] = [
                    'kode_team' => $kodeTeam,
                    'station_id' => $config['station_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        
        DB::table('team')->insert($teams);
    }
}
