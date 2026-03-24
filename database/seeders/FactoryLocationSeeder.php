<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FactoryLocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locations = [
            ['nama_factory' => 'Pabrik A', 'alamat_factory' => 'Jl. Industri No. 1, Gresik', 'priority' => 1],
            ['nama_factory' => 'Pabrik B', 'alamat_factory' => 'Jl. Industri No. 2, Surabaya', 'priority' => 2],
            ['nama_factory' => 'Pabrik C', 'alamat_factory' => 'Jl. Industri No. 3, Sidoarjo', 'priority' => 3],
        ];

        foreach ($locations as $location) {
            DB::table('factory_location')->insert($location);
        }
    }
}
