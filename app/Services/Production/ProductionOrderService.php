<?php

namespace App\Services\Production;

use App\Models\Customer;
use App\Models\ProductionOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderService
{
    public function __construct(
        private SpkService $spkService
    ) {
    }

    /**
     * Retrieve a single production order by ID.
     *
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrder
    {
        return ProductionOrder::with(['status', 'spk.createdByUser', 'customer'])->findOrFail($id);
    }

    /**
     * Retrieve a list of production orders.
     *
     * @param int|null $perPage Pass an integer to enable pagination
     * @return Collection|LengthAwarePaginator
     */
    public function list(array $filters = [], ?int $perPage = null)
    {
        $query = ProductionOrder::with(['status', 'spk.createdByUser', 'customer'])->latest();

        // GLOBAL SEARCH: Checks both Order ID OR Customer Name
        $query->when(isset($filters['search']), function ($q) use ($filters) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $filters['search']);
            $searchTerm = '%' . $escaped . '%';
            $q->where(function ($subQ) use ($searchTerm) {
                $subQ->where('order_id', 'ilike', $searchTerm)
                    ->orWhere('nama_customer', 'ilike', $searchTerm)
                    ->orWhereHas('customer', function ($customerQuery) use ($searchTerm) {
                        $customerQuery->where('nama', 'ilike', $searchTerm);
                    });
            });
        });

        // (You can keep these individual ones if you ever add advanced specific filters later)
        $query->when(isset($filters['order_id']), function ($q) use ($filters) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $filters['order_id']);
            $q->where('order_id', 'ilike', '%' . $escaped . '%');
        });
        $query->when(isset($filters['nama_customer']), function ($q) use ($filters) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $filters['nama_customer']);
            $customerSearch = '%' . $escaped . '%';
            $q->where(function ($subQ) use ($customerSearch) {
                $subQ->where('nama_customer', 'ilike', $customerSearch)
                    ->orWhereHas('customer', function ($customerQuery) use ($customerSearch) {
                        $customerQuery->where('nama', 'ilike', $customerSearch);
                    });
            });
        });

        // Filter by Status ID (Exact Match)
        $query->when(isset($filters['status_id']), function ($q) use ($filters) {
            $q->where('status_id', $filters['status_id']);
        });

        // Filter by Date Range
        $query->when(isset($filters['start_date']) && isset($filters['end_date']), function ($q) use ($filters) {
            $q->whereBetween('tanggal_order', [$filters['start_date'], $filters['end_date']]);
        });

        // Filter by Single Specific Date
        $query->when(isset($filters['tanggal_order']) && !isset($filters['start_date']), function ($q) use ($filters) {
            $q->whereDate('tanggal_order', $filters['tanggal_order']);
        });

        // Include items for statuses where the frontend needs them without a second round-trip
        $query->when(
            isset($filters['status_id']) && $filters['status_id'] === 'new',
            fn($q) => $q->with(['items.materials'])
        );

        if (isset($filters['status_id']) && in_array($filters['status_id'], ['await_material'])) {
            $query->with(['items.materials']);
            $allOrders = $query->get();
            $sequenceIds = app(\App\Services\Production\SchedulingService::class)->getSimulatedOrderSequence($allOrders);
            $orderPositions = array_flip($sequenceIds);

            $sorted = $allOrders->sortBy(function($order) use ($orderPositions) {
                return $orderPositions[$order->id] ?? 999999;
            })->values();

            if ($perPage) {
                /** @var \Illuminate\Http\Request $req */
                $req = request();
                $page = (int) $req->input('page', 1);
                return new \Illuminate\Pagination\LengthAwarePaginator(
                    $sorted->forPage($page, $perPage)->values(),
                    $sorted->count(),
                    $perPage,
                    $page,
                    ['path' => $req->url(), 'query' => $req->query()]
                );
            }
            
            return $sorted;
        }

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrder
    {
        $validated = Validator::make($data, [
            'customer_id' => ['nullable', 'integer', 'exists:customer,id'],
            'nama_customer' => ['required_without:customer_id', 'string', 'max:255'],
            'alamat_customer' => ['required_without:customer_id', 'string'],
            'nomor_telp' => ['required_without:customer_id', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tanggal_order' => ['required', 'date'],
            'status_id' => ['required', 'string', 'exists:production_statuses,id'],
            'is_urgent' => ['sometimes', 'boolean'],
        ])->validate();

        $customer = $this->resolveCustomerForCreate($validated);

        $deadlineMonths = config('production.order_deadline_months');

        $tanggalOrder = Carbon::parse($validated['tanggal_order']);
        $ddmm = $tanggalOrder->format('dm');
        $yyyy = $tanggalOrder->format('Y');

        return DB::transaction(function () use ($customer, $ddmm, $yyyy, $deadlineMonths, $validated) {
            // lockForUpdate() holds an exclusive row lock until the transaction commits,
            // serialising concurrent creates so two requests can never read the same
            // latest ID and generate the same order_id string.
            $latestOrder = ProductionOrder::lockForUpdate()->orderBy('id', 'desc')->first();
            $sequence = $latestOrder ? $latestOrder->id + 1 : 1;

            do {
                $sequenceStr = str_pad($sequence, 3, '0', STR_PAD_LEFT);
                $orderId = sprintf('%s / PH-ICN / %s / %s', $ddmm, $sequenceStr, $yyyy);
                $sequence++;
            } while (ProductionOrder::where('order_id', $orderId)->exists());

            return ProductionOrder::create([
                'customer_id' => $customer->id,
                'order_id' => $orderId,
                'nama_customer' => $customer->nama,
                'alamat_customer' => $customer->alamat,
                'tanggal_order' => $validated['tanggal_order'],
                'status_id' => $validated['status_id'],
                'production_deadline' => Carbon::parse($validated['tanggal_order'])
                    ->addMonths($deadlineMonths)
                    ->toDateString(),
                'is_urgent' => $validated['is_urgent'] ?? false,
            ])->load(['status', 'spk.createdByUser', 'customer']);
        });
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrder
    {
        $order = $this->findById($id);

        if ($order->status_id === 'await_material') {
            throw ValidationException::withMessages([
                'status_id' => ['The production order cannot be modified while it is in "await_material" status.']
            ]);
        }

        $validated = Validator::make($data, [
            'tanggal_order' => ['sometimes', 'date'],
            'status_id' => ['sometimes', 'string', 'exists:production_statuses,id'],
            'is_urgent' => ['sometimes', 'boolean'],
            'production_deadline' => ['sometimes', 'date', 'after_or_equal:tanggal_order'],
        ])->validate();

        $updateData = Arr::only($validated, [
            'tanggal_order',
            'status_id',
            'is_urgent',
            'production_deadline',
        ]);

        // Recalculate deadline from tanggal_order only when one wasn't provided explicitly
        if (isset($validated['tanggal_order']) && !isset($validated['production_deadline'])) {
            $updateData['production_deadline'] = Carbon::parse($validated['tanggal_order'])
                ->addMonths(config('production.order_deadline_months'))
                ->toDateString();
        }

        $order->update($updateData);

        return $order->fresh()->load(['status', 'spk.createdByUser', 'customer']);
    }

    public function markAsAwaitMaterial(
        int $id,
        string $awaitMaterialStatusId,
        string $newStatusId = 'new'
    ): ProductionOrder {
        $order = $this->findById($id);

        if ($order->status_id !== $newStatusId) {
            throw ValidationException::withMessages([
                'status_id' => ['Only new production orders can be changed to await material.'],
            ]);
        }

        // An order can only be processed (and scheduled) once every item has at
        // least one material assigned. Without this guard an item with no
        // material slips into await_material and gets scheduled by the
        // ProductionOrderObserver, even though it cannot actually be produced.
        $itemCount = $order->items()->count();
        if ($itemCount === 0) {
            throw ValidationException::withMessages([
                'items' => ['Order tidak dapat diproses karena belum memiliki item.'],
            ]);
        }

        $itemsWithoutMaterial = $order->items()
            ->whereDoesntHave('materials')
            ->count();

        if ($itemsWithoutMaterial > 0) {
            throw ValidationException::withMessages([
                'materials' => ["Masih ada {$itemsWithoutMaterial} item yang belum memiliki material. Lengkapi material setiap item sebelum memproses order."],
            ]);
        }

        DB::transaction(function () use ($order, $awaitMaterialStatusId): void {
            $orderForAllocation = $order->fresh(['items']);

            $materialEta = now()->addDays(14);
            $totalCompletionMinutes = 0;
            $minutesPerM2 = config('production.minutes_per_m2') ?? 60;
            $baseMinutes = config('production.base_production_minutes') ?? 120;
            $cm2PerM2 = config('production.cm2_per_m2') ?? 10000;
            $workMinPerDay = config('production.work_minutes_per_day');

            $orderItemCount = max(1, $orderForAllocation->items()->count());

            foreach ($orderForAllocation->items as $item) {
                $baseMins = $baseMinutes / $orderItemCount;
                $area = ((float) $item->panjang * (float) $item->tinggi) / $cm2PerM2;
                $varMins = $area * (int) $item->quantity * $minutesPerM2;
                $totalCompletionMinutes += $baseMins + $varMins;
            }

            $totalCompletionDays = ceil($totalCompletionMinutes / $workMinPerDay);
            $estimatedEnd = $materialEta->copy()->addDays($totalCompletionDays + 3);

            $order->update([
                'status_id'    => $awaitMaterialStatusId,
                'material_eta' => $materialEta->toDateString(),
                'estimated_end' => $estimatedEnd->toDateString(),
            ]);
        });

        return $order->fresh(['status', 'spk.createdByUser', 'customer']);
    }


    /**
     * R1: Get all await_material orders whose material_eta has passed.
     */
    public function getOverdueMaterialOrders(): array
    {
        $orders = ProductionOrder::with(['status', 'spk.createdByUser', 'customer'])
            ->where('status_id', 'await_material')
            ->whereNotNull('material_eta')
            ->where('material_eta', '<', now())
            ->orderBy('material_eta', 'asc')
            ->get();

        return $orders->map(fn($order) => [
            'id'                  => $order->id,
            'order_id'            => $order->order_id,
            'nama_customer'       => $order->nama_customer,
            'material_eta'        => $order->material_eta?->toISOString(),
            'production_deadline' => $order->production_deadline,
            'days_overdue'        => (int) now()->diffInDays($order->material_eta),
            'is_urgent'           => $order->is_urgent,
        ])->toArray();
    }

    /**
     * R1: Extend the material ETA for an overdue order.
     * Wipes pending schedules for the order's items and re-runs scheduling
     * so the new ETA is reflected in the plan.
     */
    public function extendMaterialEta(int $id, string $newEta): ProductionOrder
    {
        $order = $this->findById($id);

        if ($order->status_id !== 'await_material') {
            throw ValidationException::withMessages([
                'status_id' => ['Only await_material orders can have their ETA extended.'],
            ]);
        }

        DB::transaction(function () use ($order, $newEta) {
            $order->update(['material_eta' => $newEta]);

            $itemIds = $order->items()->pluck('id');
            \App\Models\ProductionSchedule::whereIn('production_order_item_id', $itemIds)
                ->where('status', 'pending')
                ->delete();
        });

        // scheduleUnassignedItems takes its own scheduling lock — run outside the
        // DB transaction so it can't deadlock with concurrent calls.
        app(\App\Services\Production\SchedulingService::class)->scheduleUnassignedItems();

        return $order->fresh(['status', 'spk.createdByUser', 'customer']);
    }


    /**
     * Delete a production order.
     * Only orders in 'new' status can be deleted.
     *
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $order = $this->findById($id);

        if ($order->status_id !== 'new') {
            throw ValidationException::withMessages([
                'status_id' => ['Hanya order dengan status "new" yang dapat dihapus.'],
            ]);
        }

        return DB::transaction(function () use ($order) {
            // SPK is a hasOne, delete it first if it exists
            $order->spk()?->delete();
            return $order->delete();
        });
    }


    private function resolveCustomerForCreate(array $validated): Customer
    {
        if (!empty($validated['customer_id'])) {
            return Customer::findOrFail($validated['customer_id']);
        }

        return Customer::create([
            'nama' => $validated['nama_customer'],
            'alamat' => $validated['alamat_customer'],
            'nomor_telp' => $validated['nomor_telp'],
            'email' => $validated['email'] ?? null,
        ]);
    }

}

