<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locations = [
            'A' => ['id' => 1, 'kayu' => 6, 'cat' => 6, 'acc' => 1],
            'B' => ['id' => 2, 'kayu' => 2, 'cat' => 2],
            'C' => ['id' => 3, 'kayu' => 1, 'cat' => 1],
        ];

        $teamTypes = [
            'kayu' => 'K',
            'cat' => 'C',
            'acc' => 'CC'
        ];

        $now = Carbon::now();
        $teams = [];

        foreach ($locations as $locCode => $locData) {
            $locId = $locData['id'];

            foreach ($teamTypes as $stationName => $teamCode) {
                if (isset($locData[$stationName])) {
                    $count = $locData[$stationName];
                    
                    // find the station_id
                    $station = DB::table('station')
                        ->where('factory_location_id', $locId)
                        ->where('nama_station', $stationName)
                        ->first();

                    if ($station) {
                        for ($i = 1; $i <= $count; $i++) {
                            $teams[] = [
                                'kode_team' => $locCode . $teamCode . str_pad($i, 2, '0', STR_PAD_LEFT),
                                'station_id' => $station->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    } else {
                        // fallback if DB not populated, generate without station ID lookup but we assume it's there
                        for ($i = 1; $i <= $count; $i++) {
                            // Determine placeholder station ID based on StationSeeder
                            $stationId = 1; // Default
                            if ($locId === 1) {
                                if ($stationName === 'kayu') $stationId = 1;
                                else if ($stationName === 'cat') $stationId = 2;
                                else if ($stationName === 'acc') $stationId = 3;
                            } else if ($locId === 2) {
                                if ($stationName === 'kayu') $stationId = 4;
                                else if ($stationName === 'cat') $stationId = 5;
                            } else if ($locId === 3) {
                                if ($stationName === 'kayu') $stationId = 6;
                                else if ($stationName === 'cat') $stationId = 7;
                            }

                            $teams[] = [
                                'kode_team' => $locCode . $teamCode . str_pad($i, 2, '0', STR_PAD_LEFT),
                                'station_id' => $stationId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }
            }
        }
        
        DB::table('team')->insert($teams);
    }
}
