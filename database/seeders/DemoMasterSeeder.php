<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master seeder that wires up all Demo*Seeder classes.
 *
 * Run individually by scenario:
 *   php artisan db:seed --class=DemoUrgentPrioritySeeder    # Scenario 1
 *   php artisan db:seed --class=DemoNehOptimizationSeeder   # Scenario 2
 *   php artisan db:seed --class=DemoCriticalWindowSeeder    # Scenario 3
 *   php artisan db:seed --class=DemoMaterialArrivalSeeder   # Scenario 4
 *   php artisan db:seed --class=DemoFullPipelineSeeder      # Scenario 5 (recommended for final demo)
 *
 * Or run all at once:
 *   php artisan db:seed --class=DemoMasterSeeder
 *
 * Prerequisites (must run first if database is fresh):
 *   php artisan db:seed
 *   (runs MaterialSeeder, ProductSeeder, FactoryLocationSeeder,
 *    StationSeeder, TeamSeeder, ProductionStatusSeeder, CustomerSeeder)
 *
 * Reset between demos (clears only demo orders, preserves master data):
 *   php artisan tinker --execute="
 *       \App\Models\ProductionOrder::where('order_id', 'like', '%PH-ICN / 1%')
 *           ->orWhere('order_id', 'like', '%PH-ICN / 10%')
 *           ->orWhere('order_id', 'like', '%PH-ICN / 11%')
 *           ->orWhere('order_id', 'like', '%PH-ICN / 12%')
 *           ->orWhere('order_id', 'like', '%PH-ICN / 13%')
 *           ->orWhere('order_id', 'like', '%PH-ICN / 14%')
 *           ->delete();"
 *
 * Or simply: php artisan migrate:fresh --seed   (resets entire database)
 */
class DemoMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔═══════════════════════════════════════════════════════════╗');
        $this->command->info('║         ICONSPIRIT DEMO SEEDERS — ALL SCENARIOS           ║');
        $this->command->info('╠═══════════════════════════════════════════════════════════╣');
        $this->command->info('║  S1: Urgent Priority      (seq 101-105)                   ║');
        $this->command->info('║  S2: NEH Optimization     (seq 111-115)                   ║');
        $this->command->info('║  S3: Critical Window      (seq 121-123)                   ║');
        $this->command->info('║  S4: Material Arrival     (seq 131-132)                   ║');
        $this->command->info('║  S5: Full Pipeline        (seq 141-146)                   ║');
        $this->command->info('║  S6: Urgent Mid-Production (seq 151-152)                  ║');
        $this->command->info('╚═══════════════════════════════════════════════════════════╝');
        $this->command->info('');

        $this->call([
            DemoUrgentPrioritySeeder::class,
            DemoNehOptimizationSeeder::class,
            DemoCriticalWindowSeeder::class,
            DemoMaterialArrivalSeeder::class,
            DemoFullPipelineSeeder::class,
            DemoUrgentDuringProductionSeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('All demo scenarios seeded. Total orders created: ~18');
        $this->command->info('Go to /production/pending and click "Jalankan Penjadwalan"');
        $this->command->info('TIP: For the cleanest single-scenario demo, use DemoFullPipelineSeeder only.');
    }
}
