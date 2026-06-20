<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            StationSeeder::class,
            TeamSeeder::class,
            ProductionStatusSeeder::class,
            CustomerSeeder::class,
            // ProductionOrderSeeder::class,
            // ScenarioSeeder::class,
            AdminUserSeeder::class,
            // SpkPengujianPenjadwalanSeeder::class,
            SpkKlasterPembuktianSeeder::class,
        ]);

        // ── Demo Seeders (run separately for presentations) ──────────────────
        // Each demo seeder creates orders in await_material status ready for
        // the scheduling algorithm. Run them individually to demo one scenario,
        // or use DemoMasterSeeder to seed all at once.
        //
        // php artisan db:seed --class=DemoUrgentPrioritySeeder   # urgent jumps queue
        // php artisan db:seed --class=DemoNehOptimizationSeeder  # NEH vs FIFO
        // php artisan db:seed --class=DemoCriticalWindowSeeder   # near-deadline priority
        // php artisan db:seed --class=DemoMaterialArrivalSeeder  # early arrival reschedule
        // php artisan db:seed --class=DemoFullPipelineSeeder     # all 4 tiers (best for demo)
        // php artisan db:seed --class=DemoUrgentDuringProductionSeeder # urgent arrives mid-production
        // php artisan db:seed --class=DemoMasterSeeder           # all 6 at once
        
    }
}
