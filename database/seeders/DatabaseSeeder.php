<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use phpDocumentor\Reflection\Location;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        //     'role' => 'admin',
        // ]);

        $this->call([
            MaterialSeeder::class,
            ProductSeeder::class,
            FactoryLocationSeeder::class,
            LocationTransitTimeSeeder::class,
            StationSeeder::class,
            TeamSeeder::class,
            ProductionStatusSeeder::class,
        ]);
        
    }
}
