<?php

namespace App\Console\Commands;

use App\Services\Production\ProductionScheduleService;
use Illuminate\Console\Command;

class AutoStartReadyQueues extends Command
{
    protected $signature = 'production:auto-start-queues';

    protected $description = 'Auto-start any production station whose scheduled slot has arrived (pending -> in_progress).';

    public function handle(ProductionScheduleService $service): int
    {
        $started = $service->autoStartReadyQueues();

        if ($started > 0) {
            $this->info("Auto-started {$started} station(s).");
        }

        return self::SUCCESS;
    }
}
