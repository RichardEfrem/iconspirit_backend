<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\ProductionOrderItemSection;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds 9 distinct production orders (all status = new) so the scheduling
 * algorithm can be exercised manually from the UI. Every order uses a different
 * customer and a different product mix — no two orders look alike — and three
 * are flagged urgent so urgent-priority ordering is visible.
 *
 * Progression to test:  new → await_material → on_going → finished
 *
 *  #1  Kitchen Fit-out      — URGENT, deadline 3 weeks   (cabinets, tight window)
 *  #2  Master Bedroom Set   — deadline 6 weeks           (wardrobe-heavy, large area)
 *  #3  Living + Dining      — deadline 8 weeks           (biggest job; long makespan)
 *  #4  Pantry Renovation    — deadline 7 weeks           (uniform pantry cabinets)
 *  #5  Open Office Fit-out  — deadline 2 months          (partitions + desks)
 *  #6  Mini Kitchen + Island— URGENT, deadline ~5 weeks  (priority; tight but feasible)
 *  #7  Boutique Display     — deadline 3 months          (relaxed backlog; tests NEH)
 *  #8  Guest Bedroom        — deadline 6 months          (baseline reference order)
 *  #9  Showroom Counter     — URGENT, deadline ~5.5 weeks(priority; tight but feasible)
 *
 * Note: the scheduler assumes a 14-day material lead time for await_material orders
 * (SchedulingService), so production cannot start before today+14d. Urgent deadlines
 * are set beyond that floor + processing time so every order is achievable.
 *
 * estimated_processing_hours per item ≈ (panjang × tinggi / 10000) × qty × 5 h/m²
 */
class ProductionOrderSeeder extends Seeder
{
    private Collection $materials;
    private int $seq = 1;

    public function run(): void
    {
        $products  = Product::all()->keyBy('kode_product');
        $customers = Customer::all()->values();
        $this->materials = Material::all()->keyBy('kode_material');

        if ($products->isEmpty() || $customers->isEmpty()) {
            return;
        }

        $today = Carbon::today();

        // Resolve product_id by code (P01–P14) and a distinct customer per order.
        $pid  = fn (string $code): int => $products->get($code)->id;
        $cust = fn (int $i): Customer => $customers[$i % $customers->count()];

        // ── Order 1 ─ URGENT Kitchen Fit-out ─────────────────────────────────
        $o1   = $this->makeOrder($cust(0), $today->copy()->subDays(8), [
            'is_urgent' => true,
            'deadline'  => $today->copy()->addWeeks(3),
        ]);
        $sec  = $this->makeSection($o1, 'Kitchen');
        $item = $this->makeItem($o1, $sec, $pid('P07'), 'HPL Walnut + Duco Putih Matte', 80, 85, 5, 'Kabinet bawah dapur standar');
        $this->addMaterials($item, [
            '376.00' => [ceil(80 * 85 * 5 / 15000), 280000],
            '413.00' => [2,                          220000],
            '121.00' => [ceil(80 * 85 * 5 / 20000), 420000],
            'B0261'  => [ceil((80 + 85) * 2 * 5 / 100) + 5, 15000],
            '115.00' => [5 * 4,                      25000],
        ]);
        $item = $this->makeItem($o1, $sec, $pid('P08'), 'HPL Walnut + Duco Putih Matte', 80, 70, 4, 'Kabinet atas dapur standar');
        $this->addMaterials($item, [
            '376.00' => [ceil(80 * 70 * 4 / 15000), 280000],
            '413.00' => [1,                          220000],
            '121.00' => [ceil(80 * 70 * 4 / 20000), 420000],
            'B0261'  => [ceil((80 + 70) * 2 * 4 / 100) + 4, 15000],
            '115.00' => [4 * 2,                      25000],
        ]);
        $item = $this->makeItem($o1, $sec, $pid('P09'), 'HPL Walnut', 60, 210, 1, 'Tall cabinet sudut dapur');
        $this->addMaterials($item, [
            '376.00' => [3,  280000],
            '413.00' => [1,  220000],
            '121.00' => [2,  420000],
            'B0261'  => [14, 15000],
            '115.00' => [4,  25000],
            '128.00' => [2,  120000],
        ]);

        // ── Order 2 ─ Master Bedroom Set ─────────────────────────────────────
        $o2  = $this->makeOrder($cust(1), $today->copy()->subDays(5), [
            'deadline' => $today->copy()->addWeeks(6),
        ]);
        $bed = $this->makeSection($o2, 'Master Bedroom');
        $item = $this->makeItem($o2, $bed, $pid('P05'), 'Duco Putih Glossy 4 Pintu', 200, 220, 2, 'Lemari pakaian full height');
        $this->addMaterials($item, [
            '376.00' => [6,  280000],
            '413.00' => [4,  220000],
            '178.00' => [3,  390000],
            'B0261'  => [30, 15000],
            '222.00' => [8,  65000],
            '128.00' => [4,  120000],
        ]);
        $item = $this->makeItem($o2, $bed, $pid('P14'), 'Finishing HPL + Fabric Top', 160, 200, 1, 'Divan queen size dengan laci');
        $this->addMaterials($item, [
            '376.00' => [4,  280000],
            '413.00' => [2,  220000],
            'B0261'  => [20, 15000],
            'B0161'  => [2,  85000],
        ]);
        $study = $this->makeSection($o2, 'Study Room');
        $item  = $this->makeItem($o2, $study, $pid('P03'), 'HPL Walnut + Laci 2 Susun', 150, 75, 1, 'Meja kerja dengan laci');
        $this->addMaterials($item, [
            '376.00' => [2, 280000],
            '123.00' => [1, 370000],
            'B0261'  => [8, 15000],
            '115.00' => [2, 25000],
        ]);

        // ── Order 3 ─ Living Room + Dining ───────────────────────────────────
        // Biggest job — tests long makespan and NEH optimisation.
        $o3      = $this->makeOrder($cust(2), $today->copy()->subDays(3), [
            'deadline' => $today->copy()->addWeeks(8),
        ]);
        $living  = $this->makeSection($o3, 'Ruang Tamu');
        $item    = $this->makeItem($o3, $living, $pid('P02'), 'Duco Putih Matte full panel', 200, 240, 6, 'Accent wall ruang tamu');
        $this->addMaterials($item, [
            '376.00' => [12, 280000],
            '123.00' => [6,  370000],
            'B0261'  => [ceil((200 + 240) * 2 * 6 / 100) + 10, 15000],
        ]);
        $item    = $this->makeItem($o3, $living, $pid('P01'), 'HPL Natural + Frame Aluminium', 150, 210, 2, 'Partisi ruang tamu–dapur');
        $this->addMaterials($item, [
            '376.00' => [4,  280000],
            '121.00' => [2,  420000],
            'B0261'  => [ceil((150 + 210) * 2 * 2 / 100) + 5, 15000],
            '455.00' => [4,  95000],
        ]);
        $dining  = $this->makeSection($o3, 'Ruang Makan');
        $item    = $this->makeItem($o3, $dining, $pid('P03'), 'HPL Walnut Top + Kaki Besi', 180, 80, 1, 'Meja makan 6 kursi');
        $this->addMaterials($item, [
            '376.00' => [3, 280000],
            '178.00' => [1, 390000],
            'B0262'  => [8, 18000],
            '88.00'  => [4, 75000],
        ]);
        $item    = $this->makeItem($o3, $dining, $pid('P04'), 'HPL Taco 186 AA + Kaca Pintu Geser', 100, 200, 1, 'Display cabinet ruang makan');
        $this->addMaterials($item, [
            '376.00' => [4,  280000],
            '413.00' => [2,  220000],
            '123.00' => [2,  370000],
            'B0262'  => [14, 18000],
            '115.00' => [4,  25000],
        ]);

        // ── Order 4 ─ Pantry Renovation ──────────────────────────────────────
        $o4      = $this->makeOrder($cust(3), $today->copy()->subDays(14), [
            'deadline' => $today->copy()->addWeeks(7),
        ]);
        $pantry  = $this->makeSection($o4, 'Pantry');
        $pantryItems = [
            [$pid('P10'), 'HPL Taco 868 LU',  60, 210, 2, 'Pantry tall cabinet'],
            [$pid('P11'), 'HPL Taco 868 LU',  60,  85, 3, 'Pantry kabinet bawah'],
            [$pid('P12'), 'HPL Taco 868 LU',  60,  70, 2, 'Pantry kabinet atas'],
        ];
        foreach ($pantryItems as [$pId, $spec, $p, $t, $q, $note]) {
            $item = $this->makeItem($o4, $pantry, $pId, $spec, $p, $t, $q, $note);
            $this->addMaterials($item, [
                '376.00' => [max(1, (int) ceil($p * $t * $q / 15000)), 280000],
                '413.00' => [1,                                         220000],
                '121.00' => [max(1, (int) ceil($p * $t * $q / 22000)), 420000],
                'B0261'  => [(int) ceil(($p + $t) * 2 * $q / 100) + 3, 15000],
                '115.00' => [$q * 2,                                    25000],
            ]);
        }

        // ── Order 5 ─ Open Office Fit-out ────────────────────────────────────
        // Non-urgent backlog — scheduler fills idle gaps with these.
        $o5     = $this->makeOrder($cust(4), $today->copy(), [
            'deadline' => $today->copy()->addMonths(2),
        ]);
        $office = $this->makeSection($o5, 'Open Office');
        $item   = $this->makeItem($o5, $office, $pid('P01'), 'HPL Taco TH 852 J + Frame Huben Doff', 120, 200, 4, 'Partisi modular antar meja');
        $this->addMaterials($item, [
            '376.00' => [ceil(120 * 200 * 4 / 15000), 280000],
            '123.00' => [ceil(120 * 200 * 4 / 20000), 370000],
            'B0261'  => [(int) ceil((120 + 200) * 2 * 4 / 100) + 6, 15000],
            'B0109'  => [4 * 2,                        85000],
        ]);
        $item   = $this->makeItem($o5, $office, $pid('P03'), 'HPL Taco 186 AA + Laci 3 Susun', 160, 75, 3, 'Meja kerja karyawan');
        $this->addMaterials($item, [
            '376.00' => [3, 280000],
            '123.00' => [2, 370000],
            'B0261'  => [(int) ceil((160 + 75) * 2 * 3 / 100) + 4, 15000],
            '115.00' => [6, 25000],
        ]);

        // ── Order 6 ─ URGENT Mini Kitchen + Island ───────────────────────────
        // Urgent → scheduled before non-urgent; deadline clears the 14-day material
        // floor + processing so it stays achievable.
        $o6     = $this->makeOrder($cust(5), $today->copy()->subDays(2), [
            'is_urgent' => true,
            'deadline'  => $today->copy()->addDays(35),
        ]);
        $mkitch = $this->makeSection($o6, 'Mini Kitchen');
        $item   = $this->makeItem($o6, $mkitch, $pid('P07'), 'HPL Taco 186 AA + Duco Matte', 60, 85, 3, 'Kabinet bawah mini kitchen');
        $this->addMaterials($item, [
            '376.00' => [max(1, (int) ceil(60 * 85 * 3 / 18000)), 280000],
            '413.00' => [1,                                        220000],
            '123.00' => [max(1, (int) ceil(60 * 85 * 3 / 22000)), 370000],
            'B0261'  => [(int) ceil((60 + 85) * 2 * 3 / 100) + 4, 15000],
            '115.00' => [3 * 4,                                    25000],
        ]);
        $item   = $this->makeItem($o6, $mkitch, $pid('P08'), 'HPL Taco 186 AA + Duco Matte', 60, 70, 3, 'Kabinet atas mini kitchen');
        $this->addMaterials($item, [
            '376.00' => [max(1, (int) ceil(60 * 70 * 3 / 18000)), 280000],
            '413.00' => [1,                                        220000],
            '123.00' => [max(1, (int) ceil(60 * 70 * 3 / 22000)), 370000],
            'B0261'  => [(int) ceil((60 + 70) * 2 * 3 / 100) + 3, 15000],
            '115.00' => [3 * 2,                                    25000],
        ]);
        $item   = $this->makeItem($o6, $mkitch, $pid('P06'), 'Solid Top + HPL Body', 120, 90, 1, 'Kitchen island dengan storage');
        $this->addMaterials($item, [
            '376.00' => [4,  280000],
            '413.00' => [2,  220000],
            '123.00' => [2,  370000],
            'B0262'  => [12, 18000],
            '115.00' => [6,  25000],
        ]);

        // ── Order 7 ─ Boutique Store Display ─────────────────────────────────
        // Relaxed backlog — exercises NEH ordering among low-priority jobs.
        $o7    = $this->makeOrder($cust(6), $today->copy()->subDay(), [
            'deadline' => $today->copy()->addMonths(3),
        ]);
        $store = $this->makeSection($o7, 'Show Room');
        $storeItems = [
            [$pid('P04'), 'HPL Splendor 7607 + Kaca Display',  100, 200, 4, 'Display cabinet utama produk'],
            [$pid('P02'), 'Duco Putih Glossy',                  120, 240, 4, 'Wallpanel branding area'],
            [$pid('P13'), 'Duco Putih Glossy + HPL dalam',       90, 210, 2, 'Pintu kamuflase storage belakang'],
        ];
        foreach ($storeItems as [$pId, $spec, $p, $t, $q, $note]) {
            $item = $this->makeItem($o7, $store, $pId, $spec, $p, $t, $q, $note);
            $this->addMaterials($item, [
                '376.00' => [max(1, (int) ceil($p * $t * $q / 15000)), 280000],
                '413.00' => [1,                                         220000],
                '156.00' => [max(1, (int) ceil($p * $t * $q / 22000)), 490000],
                'B0261'  => [(int) ceil(($p + $t) * 2 * $q / 100) + 5, 15000],
                '115.00' => [$q * 2,                                    25000],
            ]);
        }

        // ── Order 8 ─ Guest Bedroom (reference) ──────────────────────────────
        // Oldest order, very relaxed deadline — baseline/comparison reference.
        $o8  = $this->makeOrder($cust(7), $today->copy()->subMonths(2), [
            'deadline' => $today->copy()->addMonths(6),
        ]);
        $guest = $this->makeSection($o8, 'Kamar Tamu');
        $guestItems = [
            [$pid('P05'), 'Duco Putih Matte 4 Pintu',  180, 220, 1, 'Lemari pakaian'],
            [$pid('P03'), 'HPL Walnut natural',          140,  75, 1, 'Meja belajar'],
            [$pid('P04'), 'HPL + Kaca pintu geser',       80, 180, 1, 'Display cabinet'],
        ];
        foreach ($guestItems as [$pId, $spec, $p, $t, $q, $note]) {
            $item = $this->makeItem($o8, $guest, $pId, $spec, $p, $t, $q, $note);
            $this->addMaterials($item, [
                '376.00' => [max(2, (int) ceil($p * $t / 15000)), 280000],
                '121.00' => [max(1, (int) ceil($p * $t / 22000)), 420000],
                'B0261'  => [(int) ceil(($p + $t) * 2 / 100) + 5, 15000],
                '115.00' => [4,                                    25000],
            ]);
        }

        // ── Order 9 ─ URGENT Showroom Counter ────────────────────────────────
        // Urgent → jumps the queue; deadline set past the 14-day material floor +
        // processing so the tight job is still achievable.
        $o9    = $this->makeOrder($cust(8), $today->copy()->subDay(), [
            'is_urgent' => true,
            'deadline'  => $today->copy()->addDays(38),
        ]);
        $front = $this->makeSection($o9, 'Front Counter');
        $item  = $this->makeItem($o9, $front, $pid('P06'), 'Solid Surface Top + HPL Body', 240, 95, 1, 'Counter kasir utama');
        $this->addMaterials($item, [
            '376.00' => [6,  280000],
            '413.00' => [3,  220000],
            '156.00' => [3,  490000],
            'B0262'  => [16, 18000],
            '115.00' => [6,  25000],
        ]);
        $item  = $this->makeItem($o9, $front, $pid('P04'), 'HPL Splendor + Kaca', 90, 200, 2, 'Display produk samping counter');
        $this->addMaterials($item, [
            '376.00' => [4,  280000],
            '413.00' => [2,  220000],
            '156.00' => [2,  490000],
            'B0262'  => [12, 18000],
            '115.00' => [4,  25000],
        ]);
        $item  = $this->makeItem($o9, $front, $pid('P01'), 'HPL Natural + Frame Aluminium', 100, 210, 1, 'Partisi pembatas antrian');
        $this->addMaterials($item, [
            '376.00' => [2,  280000],
            '121.00' => [1,  420000],
            'B0261'  => [9,  15000],
            '455.00' => [2,  95000],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeOrder(Customer $customer, Carbon $orderDate, array $opts): ProductionOrder
    {
        $ddmm = $orderDate->format('dm');
        $yyyy = $orderDate->format('Y');
        $seq  = str_pad($this->seq++, 3, '0', STR_PAD_LEFT);

        return ProductionOrder::create([
            'customer_id'         => $customer->id,
            'order_id'            => "{$ddmm} / PH-ICN / {$seq} / {$yyyy}",
            'nama_customer'       => $customer->nama,
            'alamat_customer'     => $customer->alamat,
            'tanggal_order'       => $orderDate,
            'status_id'           => 'new',
            'is_urgent'           => $opts['is_urgent'] ?? false,
            'production_deadline' => $opts['deadline']  ?? null,
        ]);
    }

    private function makeSection(ProductionOrder $order, string $name): ProductionOrderItemSection
    {
        return ProductionOrderItemSection::create([
            'production_order_id' => $order->id,
            'name'                => $name,
        ]);
    }

    private function makeItem(
        ProductionOrder $order,
        ProductionOrderItemSection $section,
        int $productId,
        string $spec,
        int $panjang,
        int $tinggi,
        int $qty,
        ?string $note = null
    ): ProductionOrderItem {
        // spesifikasi_produk column was dropped (migration 2026_05_27_000001), so the
        // finishing spec is folded into keterangan to keep it visible in the UI.
        $keterangan = $note ? "{$note} · {$spec}" : $spec;

        return ProductionOrderItem::create([
            'production_order_id'              => $order->id,
            'production_order_item_section_id' => $section->id,
            'product_id'                       => $productId,
            'panjang'                          => $panjang,
            'tinggi'                           => $tinggi,
            'quantity'                         => $qty,
            'keterangan'                       => $keterangan,
        ]);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $mats  [kode_material => [qty, cost_per_unit]]
     */
    private function addMaterials(ProductionOrderItem $item, array $mats): void
    {
        foreach ($mats as $kode => [$qty, $cost]) {
            $material = $this->materials->get($kode);
            if (!$material) {
                continue;
            }

            ProductionOrderItemMaterial::create([
                'production_order_item_id' => $item->id,
                'material_id'              => $material->id,
                'quantity'                 => max(1, $qty),
                'cost'                     => $cost,
            ]);
        }
    }
}
