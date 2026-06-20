<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FactoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['id' => 1, 'nama_factory' => 'Pabrik A', 'alamat_factory' => 'Jl. Industri', 'priority' => 1],
        ];

        foreach ($locations as $location) {
            DB::table('factory_location')->insert($location);
        }
    }
}
