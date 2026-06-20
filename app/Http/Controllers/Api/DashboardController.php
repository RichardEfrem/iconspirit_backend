<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\MaterialService;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\ProductionScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Single aggregate endpoint for the dashboard.
     * Returns all data needed by the frontend dashboard in one response,
     * replacing 8 separate API calls with 1.
     */
    public function index(
        Request $request,
        ProductionOrderService $orderService,
        ProductionScheduleService $scheduleService,
        MaterialService $materialService,
    ) {
        $now = now();
        $todayStr = $now->toDateString();
        $weekStartStr = $now->copy()->subDays(6)->toDateString();

        // Cache the full dashboard payload for 15 seconds
        $data = Cache::remember('dashboard_data', 15, function () use (
            $orderService, $scheduleService, $materialService,
            $weekStartStr, $todayStr,
        ) {
            // Fetch all data in parallel-ish (PHP is sequential but this
            // avoids 8 HTTP roundtrips from the Next.js server)
            $newRes = $orderService->list(['status_id' => 'new'], 10);
            $awaitMatRes = $orderService->list(['status_id' => 'await_material'], 10);
            $ongoingRes = $orderService->list(['status_id' => 'on_going'], 20);
            $finishedWeekRes = $orderService->list([
                'status_id' => 'finished',
                'start_date' => $weekStartStr,
                'end_date' => $todayStr,
            ], 1);

            $ongoingProgress = $scheduleService->getAllOngoingProgress();
            $teamSchedules = $scheduleService->getOngoingTeamSchedules();
            $stockCounts = $materialService->getStockCounts();
            $lowStockItems = $materialService->getAllMaterials(['status' => 'LOW_STOCK']);

            return [
                'counts' => [
                    'new' => $this->extractTotal($newRes),
                    'await_material' => $this->extractTotal($awaitMatRes),
                    'ongoing' => $this->extractTotal($ongoingRes),
                    'finished_this_week' => $this->extractTotal($finishedWeekRes),
                ],
                'active_orders' => $this->mergeOrders($newRes, $awaitMatRes, $ongoingRes),
                'ongoing_progress' => $ongoingProgress,
                'stock_counts' => $stockCounts,
                'low_stock_items' => $lowStockItems instanceof \Illuminate\Pagination\LengthAwarePaginator
                    ? $lowStockItems->items()
                    : $lowStockItems,
                'team_schedules' => $teamSchedules,
            ];
        });

        return $this->successResponse($data, 'Dashboard data retrieved successfully');
    }

    private function extractTotal($result): int
    {
        if ($result instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return $result->total();
        }

        if ($result instanceof \Illuminate\Database\Eloquent\Collection) {
            return $result->count();
        }

        return 0;
    }

    private function mergeOrders(...$results): array
    {
        $merged = collect();

        foreach ($results as $result) {
            if ($result instanceof \Illuminate\Pagination\LengthAwarePaginator) {
                $merged = $merged->merge($result->items());
            } elseif ($result instanceof \Illuminate\Database\Eloquent\Collection) {
                $merged = $merged->merge($result);
            }
        }

        return $merged->values()->toArray();
    }
}
