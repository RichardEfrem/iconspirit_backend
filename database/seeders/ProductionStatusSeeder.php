<?php

namespace Database\Seeders;

use App\Models\ProductionStatus;
use Illuminate\Database\Seeder;

class ProductionStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'id' => 'new',
                'label' => 'New',
                'color' => 'blue',
            ],
            [
                'id' => 'process',
                'label' => 'Process',
                'color' => 'orange',
            ],
            [
                'id' => 'on_going',
                'label' => 'On Going',
                'color' => 'cyan',
            ],
            [
                'id' => 'finished',
                'label' => 'Finished',
                'color' => 'green',
            ],
            [
                'id' => 'cancelled',
                'label' => 'Cancelled',
                'color' => 'red',
            ],
        ];

        foreach ($statuses as $status) {
            ProductionStatus::updateOrCreate(
                ['id' => $status['id']],
                $status
            );
        }
    }
}
