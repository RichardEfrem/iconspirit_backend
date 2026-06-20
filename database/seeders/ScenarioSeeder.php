<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\FactoryLocation;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\ProductionOrderItemSection;
use App\Models\ProductionSchedule;
use App\Models\Spk;
use App\Models\Station;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Multi-stage production scenarios — exercises the full workflow without
 * manual UI setup. Run after all base seeders.
 *
 *  S1  Hotel Room Renovation      — await_material  (SPK issued, stock not arrived yet)
 *  S2  Home Full Renovation        — on_going        (kayu done, cat in-progress, acc pending)
 *  S3  Restaurant Fit-out          — finished        (all done, all materials deducted)
 *  S4  Urgent Apartment Kitchen    — new, URGENT     (just received, no SPK/schedule yet)
 *  S5  Corporate HQ Fit-out        — new             (large 4-section backlog, relaxed deadline)
 *  S6  Cancelled Condo Order       — cancelled       (customer pulled out before production)
 */
class ScenarioSeeder extends Seeder
{
    private Collection $materials;
    private Collection $products;
    private Collection $customers;
    private Collection $stations;
    private Collection $teams;
    private ?FactoryLocation $factory;
    private int $seq = 20;  // leaves gap after ProductionOrderSeeder (seq 1–8)

    public function run(): void
    {
        $this->materials = Material::all()->keyBy('kode_material');
        $this->products  = Product::all()->keyBy('kode_product');
        $this->customers = Customer::all()->values();
        $this->stations  = Station::all()->keyBy('nama_station');
        $this->teams     = Team::all();
        $this->factory   = FactoryLocation::first();

        if ($this->products->isEmpty() || $this->customers->isEmpty()) {
            return;
        }

        $today = Carbon::today();

        $this->seedHotelRenovation($today);
        $this->seedHomeRenovation($today);
        $this->seedRestaurantFitout($today);
        $this->seedUrgentApartmentKitchen($today);
        $this->seedCorporateHQ($today);
        $this->seedCancelledOrder($today);
    }

    // =========================================================================
    // S1 — Hotel Room Renovation   status: await_material
    //      SPK issued 1 day after order; materials arriving in 5 days.
    //      Tests the "waiting on stock" state before scheduling starts.
    // =========================================================================
    private function seedHotelRenovation(Carbon $today): void
    {
        $orderDate = $today->copy()->subDays(10);

        $order = $this->makeOrder($this->customers[5], $orderDate, [
            'status_id'           => 'await_material',
            'production_deadline' => $today->copy()->addWeeks(8),
            'material_eta'        => $today->copy()->addDays(5),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        // — Standard Room ─────────────────────────────────────────────────────
        $std = $this->makeSection($order, 'Standard Room');

        // wardrobe 200×220 qty 6 → 26.4 m² → ~132 h
        $i1 = $this->makeItem($order, $std, $pid('P05'), 'HPL Taco 868 LU 4 Pintu', 200, 220, 6,
            'Lemari kamar hotel standard');
        $this->addMaterials($i1, [
            '376.00' => [ceil(200 * 220 * 6 / 15000), 280000],
            '413.00' => [3,                             220000],
            '121.00' => [ceil(200 * 220 * 6 / 20000), 420000],
            'B0261'  => [ceil((200 + 220) * 2 * 6 / 100) + 10, 15000],
            '222.00' => [6 * 4,                         65000],
        ]);

        // desk 140×75 qty 6 → 6.3 m² → ~31.5 h
        $i2 = $this->makeItem($order, $std, $pid('P03'), 'HPL Walnut Matte + Laci', 140, 75, 6,
            'Meja rias kamar hotel');
        $this->addMaterials($i2, [
            '376.00' => [2,                              280000],
            '121.00' => [1,                              420000],
            'B0261'  => [ceil((140 + 75) * 2 * 6 / 100) + 8, 15000],
            '115.00' => [6 * 2,                          25000],
        ]);

        // — Junior Suite ───────────────────────────────────────────────────────
        $suite = $this->makeSection($order, 'Junior Suite');

        // wardrobe 240×220 qty 2 → 10.56 m² → ~52.8 h
        $i3 = $this->makeItem($order, $suite, $pid('P05'), 'Duco Putih Glossy 6 Pintu', 240, 220, 2,
            'Wardrobe suite eksklusif');
        $this->addMaterials($i3, [
            '376.00' => [8,  280000],
            '413.00' => [4,  220000],
            '178.00' => [3,  390000],
            'B0261'  => [ceil((240 + 220) * 2 * 2 / 100) + 12, 15000],
            '128.00' => [2 * 4, 120000],
        ]);

        // display 100×200 qty 2 → 4.0 m² → ~20 h
        $i4 = $this->makeItem($order, $suite, $pid('P04'), 'HPL Splendor + Kaca Display', 100, 200, 2,
            'Display cabinet suite');
        $this->addMaterials($i4, [
            '376.00' => [3,  280000],
            '413.00' => [2,  220000],
            '156.00' => [2,  490000],
            'B0262'  => [14, 18000],
            '115.00' => [2 * 2, 25000],
        ]);

        $this->makeSpk($order, $orderDate->copy()->addDay());
    }

    // =========================================================================
    // S2 — Home Full Renovation   status: on_going
    //      SPK issued 2 days after order; production started 14 days ago.
    //      Living room items: fully finished through all stations.
    //      Master bedroom: kayu done, cat in-progress.
    //      Kids room: nothing started yet (all schedules = scheduled).
    // =========================================================================
    private function seedHomeRenovation(Carbon $today): void
    {
        $orderDate = $today->copy()->subDays(21);
        $start     = $today->copy()->subDays(14);

        $order = $this->makeOrder($this->customers[6], $orderDate, [
            'status_id'           => 'on_going',
            'production_deadline' => $today->copy()->addWeeks(3),
            'production_start'    => $start->copy(),
            'estimated_end'       => $today->copy()->addWeeks(3),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        // — Ruang Tamu — stations: kayu ✓ cat ✓ ──────────────────────────────
        $living = $this->makeSection($order, 'Ruang Tamu');

        $i1 = $this->makeItem($order, $living, $pid('P02'), 'Duco Putih Matte full panel', 180, 240, 4,
            'Wallpanel accent ruang tamu');
        $this->addMaterials($i1, [
            '376.00' => [ceil(180 * 240 * 4 / 15000), 280000],
            '123.00' => [ceil(180 * 240 * 4 / 20000), 370000],
            'B0261'  => [ceil((180 + 240) * 2 * 4 / 100) + 8, 15000],
        ]);

        $i2 = $this->makeItem($order, $living, $pid('P01'), 'HPL Natural + Aluminium Frame', 120, 210, 2,
            'Partisi ruang tamu');
        $this->addMaterials($i2, [
            '376.00' => [3, 280000],
            '121.00' => [2, 420000],
            'B0261'  => [ceil((120 + 210) * 2 * 2 / 100) + 5, 15000],
            '455.00' => [4, 95000],
        ]);

        // — Kamar Tidur Utama — kayu ✓ cat in-progress ───────────────────────
        $bedroom = $this->makeSection($order, 'Kamar Tidur Utama');

        $i3 = $this->makeItem($order, $bedroom, $pid('P05'), 'Duco Putih Glossy 4 Pintu', 200, 220, 1,
            'Lemari pakaian full height');
        $this->addMaterials($i3, [
            '376.00' => [6, 280000],
            '413.00' => [3, 220000],
            '123.00' => [2, 370000],
            'B0261'  => [25, 15000],
            '222.00' => [4, 65000],
        ]);

        $i4 = $this->makeItem($order, $bedroom, $pid('P14'), 'HPL Walnut + Fabric Top', 160, 200, 1,
            'Divan queen size dengan laci');
        $this->addMaterials($i4, [
            '376.00' => [4,  280000],
            '413.00' => [2,  220000],
            'B0261'  => [18, 15000],
            'B0161'  => [2,  85000],
        ]);

        // — Kamar Anak — nothing started yet ──────────────────────────────────
        $kids = $this->makeSection($order, 'Kamar Anak');

        $i5 = $this->makeItem($order, $kids, $pid('P05'), 'HPL Biru Muda 2 Pintu', 120, 200, 1,
            'Lemari anak minimalis');
        $this->addMaterials($i5, [
            '376.00' => [3, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [14, 15000],
            '115.00' => [4,  25000],
        ]);

        $this->makeSpk($order, $orderDate->copy()->addDays(2));

        // — Production schedules ───────────────────────────────────────────────
        $kayu = $this->stations->get('kayu');
        $cat  = $this->stations->get('cat');
        $acc  = $this->stations->get('acc');

        if (!$kayu || !$cat || !$acc) {
            return;
        }

        $kayuTeams = $this->teams->where('station_id', $kayu->id)->values();
        $catTeams  = $this->teams->where('station_id', $cat->id)->values();
        $accTeams  = $this->teams->where('station_id', $acc->id)->values();

        $d = fn (int $days) => $start->copy()->addDays($days);

        // i1 wallpanel: kayu d0→d3 ✓  cat d3→d5 ✓
        $this->makeSchedule($i1, $kayu, $kayuTeams[0],
            $d(0), $d(3), 'completed', $d(0), $d(3)->subHours(2));
        $this->makeSchedule($i1, $cat, $catTeams[0],
            $d(3), $d(5), 'completed', $d(3), $d(5)->subHours(1));

        // i2 partisi: kayu d0→d2 ✓  cat d5→d7 ✓
        $this->makeSchedule($i2, $kayu, $kayuTeams[1],
            $d(0), $d(2), 'completed', $d(0), $d(2));
        $this->makeSchedule($i2, $cat, $catTeams[0],
            $d(5), $d(7), 'completed', $d(5), $d(7)->subHours(3));

        // i3 wardrobe: kayu d2→d7 ✓  cat d7→today+3 in_progress
        $this->makeSchedule($i3, $kayu, $kayuTeams[2],
            $d(2), $d(7), 'completed', $d(2), $d(7));
        $this->makeSchedule($i3, $cat, $catTeams[1],
            $d(7), $today->copy()->addDays(3), 'in_progress', $d(7), null);

        // i4 divan: kayu d3→d8 ✓  cat today+3→today+6 scheduled
        $this->makeSchedule($i4, $kayu, $kayuTeams[3],
            $d(3), $d(8), 'completed', $d(3), $d(8)->subHours(4));
        $this->makeSchedule($i4, $cat, $catTeams[2],
            $today->copy()->addDays(3), $today->copy()->addDays(6), 'scheduled', null, null);

        // i5 kids wardrobe: all three stations scheduled (not yet started)
        $this->makeSchedule($i5, $kayu, $kayuTeams[0],
            $today->copy()->addDays(1), $today->copy()->addDays(4), 'scheduled', null, null);
        $this->makeSchedule($i5, $cat, $catTeams[0],
            $today->copy()->addDays(4), $today->copy()->addDays(6), 'scheduled', null, null);
        $this->makeSchedule($i5, $acc, $accTeams[0],
            $today->copy()->addDays(6), $today->copy()->addDays(7), 'scheduled', null, null);
    }

    // =========================================================================
    // S3 — Restaurant Fit-out   status: finished
    //      Ordered 3 months ago, completed 10 days ago.
    //      All schedules completed; all materials deducted.
    //      Good reference for "what a closed order looks like".
    // =========================================================================
    private function seedRestaurantFitout(Carbon $today): void
    {
        $orderDate    = $today->copy()->subMonths(3);
        $prodStart    = $today->copy()->subDays(60);
        $prodEnd      = $today->copy()->subDays(10);

        $order = $this->makeOrder($this->customers[7], $orderDate, [
            'status_id'           => 'finished',
            'production_deadline' => $today->copy()->subDays(12),
            'production_start'    => $prodStart->copy(),
            'estimated_end'       => $prodEnd->copy(),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        // — Dining Area ───────────────────────────────────────────────────────
        $dining = $this->makeSection($order, 'Dining Area');

        $i1 = $this->makeItem($order, $dining, $pid('P04'), 'HPL Walnut + Kaca Display', 80, 200, 8,
            'Display cabinet makanan restoran');
        $this->addMaterials($i1, [
            '376.00' => [ceil(80 * 200 * 8 / 15000), 280000],
            '413.00' => [4, 220000],
            '178.00' => [ceil(80 * 200 * 8 / 22000), 390000],
            'B0261'  => [ceil((80 + 200) * 2 * 8 / 100) + 10, 15000],
            '115.00' => [8 * 2, 25000],
        ], isDeducted: true);

        $i2 = $this->makeItem($order, $dining, $pid('P02'), 'Duco Putih Matte', 120, 240, 6,
            'Wallpanel tema restoran');
        $this->addMaterials($i2, [
            '376.00' => [ceil(120 * 240 * 6 / 15000), 280000],
            '123.00' => [ceil(120 * 240 * 6 / 20000), 370000],
            'B0261'  => [ceil((120 + 240) * 2 * 6 / 100) + 8, 15000],
        ], isDeducted: true);

        // — Bar Area ──────────────────────────────────────────────────────────
        $bar = $this->makeSection($order, 'Bar Area');

        $i3 = $this->makeItem($order, $bar, $pid('P11'), 'HPL Splendor 7607 + Top HPL', 80, 85, 6,
            'Kabinet bar bawah');
        $this->addMaterials($i3, [
            '376.00' => [ceil(80 * 85 * 6 / 15000), 280000],
            '413.00' => [2, 220000],
            '156.00' => [ceil(80 * 85 * 6 / 22000), 490000],
            'B0261'  => [ceil((80 + 85) * 2 * 6 / 100) + 6, 15000],
            '115.00' => [6 * 2, 25000],
        ], isDeducted: true);

        $i4 = $this->makeItem($order, $bar, $pid('P01'), 'HPL Splendor + Kaca', 100, 200, 2,
            'Partisi area bar');
        $this->addMaterials($i4, [
            '376.00' => [3, 280000],
            '156.00' => [2, 490000],
            'B0262'  => [14, 18000],
        ], isDeducted: true);

        $this->makeSpk($order, $orderDate->copy()->addDays(3));

        // — Completed schedules ────────────────────────────────────────────────
        $kayu = $this->stations->get('kayu');
        $cat  = $this->stations->get('cat');
        $acc  = $this->stations->get('acc');

        if (!$kayu || !$cat || !$acc) {
            return;
        }

        $kayuTeams = $this->teams->where('station_id', $kayu->id)->values();
        $catTeams  = $this->teams->where('station_id', $cat->id)->values();
        $accTeams  = $this->teams->where('station_id', $acc->id)->values();

        foreach ([$i1, $i2, $i3, $i4] as $idx => $item) {
            $ks = $prodStart->copy()->addDays($idx * 9);
            $ke = $ks->copy()->addDays(5);
            $cs = $ke->copy()->addDay();
            $ce = $cs->copy()->addDays(3);
            $as = $ce->copy()->addDay();
            $ae = $as->copy()->addDay();

            $this->makeSchedule($item, $kayu, $kayuTeams[$idx % $kayuTeams->count()],
                $ks, $ke, 'completed', $ks, $ke->copy()->subHours(2));
            $this->makeSchedule($item, $cat, $catTeams[$idx % $catTeams->count()],
                $cs, $ce, 'completed', $cs, $ce->copy()->subHours(1));
            $this->makeSchedule($item, $acc, $accTeams[$idx % $accTeams->count()],
                $as, $ae, 'completed', $as, $ae);
        }
    }

    // =========================================================================
    // S4 — Urgent Apartment Kitchen   status: new  is_urgent: true
    //      Received today; 2-week deadline; no SPK or schedules yet.
    //      Tests that urgent flag bubbles this to the top of the queue.
    // =========================================================================
    private function seedUrgentApartmentKitchen(Carbon $today): void
    {
        $order = $this->makeOrder($this->customers[8], $today->copy(), [
            'status_id'           => 'new',
            'is_urgent'           => true,
            'production_deadline' => $today->copy()->addWeeks(2),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        $kit = $this->makeSection($order, 'Kitchen Set');

        // base 60×85 qty 4 → 2.04 m² → ~10.2 h
        $i1 = $this->makeItem($order, $kit, $pid('P07'), 'HPL Taco 186 AA Matte', 60, 85, 4,
            'Kabinet bawah kitchen set');
        $this->addMaterials($i1, [
            '376.00' => [max(1, (int) ceil(60 * 85 * 4 / 18000)), 280000],
            '413.00' => [1, 220000],
            '123.00' => [max(1, (int) ceil(60 * 85 * 4 / 22000)), 370000],
            'B0261'  => [(int) ceil((60 + 85) * 2 * 4 / 100) + 5, 15000],
            '115.00' => [4 * 4, 25000],
        ]);

        // wall 60×70 qty 4 → 1.68 m² → ~8.4 h
        $i2 = $this->makeItem($order, $kit, $pid('P08'), 'HPL Taco 186 AA Matte', 60, 70, 4,
            'Kabinet atas kitchen set');
        $this->addMaterials($i2, [
            '376.00' => [max(1, (int) ceil(60 * 70 * 4 / 18000)), 280000],
            '413.00' => [1, 220000],
            '123.00' => [max(1, (int) ceil(60 * 70 * 4 / 22000)), 370000],
            'B0261'  => [(int) ceil((60 + 70) * 2 * 4 / 100) + 4, 15000],
            '115.00' => [4 * 2, 25000],
        ]);

        // tall 60×210 qty 1 → 1.26 m² → ~6.3 h
        $i3 = $this->makeItem($order, $kit, $pid('P09'), 'HPL Taco 186 AA Matte', 60, 210, 1,
            'Tall cabinet dapur');
        $this->addMaterials($i3, [
            '376.00' => [3, 280000],
            '413.00' => [1, 220000],
            '123.00' => [1, 370000],
            'B0261'  => [14, 15000],
            '115.00' => [4,  25000],
            '128.00' => [2, 120000],
        ]);
    }

    // =========================================================================
    // S5 — Corporate HQ Fit-out   status: new
    //      4-month deadline, 4 sections, largest item count of any order.
    //      Tests NEH ordering among non-urgent backlog; no SPK yet.
    // =========================================================================
    private function seedCorporateHQ(Carbon $today): void
    {
        $order = $this->makeOrder($this->customers[9], $today->copy()->subDays(2), [
            'status_id'           => 'new',
            'production_deadline' => $today->copy()->addMonths(4),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        // — Reception Area ─────────────────────────────────────────────────────
        $reception = $this->makeSection($order, 'Reception Area');

        $i1 = $this->makeItem($order, $reception, $pid('P01'), 'HPL Taco TH 852 J + Frame Huben', 180, 220, 3,
            'Partisi area resepsionis');
        $this->addMaterials($i1, [
            '376.00' => [ceil(180 * 220 * 3 / 15000), 280000],
            '123.00' => [ceil(180 * 220 * 3 / 20000), 370000],
            'B0261'  => [ceil((180 + 220) * 2 * 3 / 100) + 8, 15000],
            'B0109'  => [3 * 2, 85000],
        ]);

        $i2 = $this->makeItem($order, $reception, $pid('P02'), 'Duco Putih Matte', 200, 250, 5,
            'Wallpanel branding perusahaan');
        $this->addMaterials($i2, [
            '376.00' => [ceil(200 * 250 * 5 / 15000), 280000],
            '123.00' => [ceil(200 * 250 * 5 / 20000), 370000],
            'B0261'  => [ceil((200 + 250) * 2 * 5 / 100) + 10, 15000],
        ]);

        // — Work Area ──────────────────────────────────────────────────────────
        $workArea = $this->makeSection($order, 'Work Area');

        $i3 = $this->makeItem($order, $workArea, $pid('P03'), 'HPL Taco 186 AA + Laci 3 Susun', 160, 75, 12,
            'Meja kerja karyawan');
        $this->addMaterials($i3, [
            '376.00' => [ceil(160 * 75 * 12 / 18000), 280000],
            '123.00' => [ceil(160 * 75 * 12 / 22000), 370000],
            'B0261'  => [ceil((160 + 75) * 2 * 12 / 100) + 15, 15000],
            '115.00' => [12 * 2, 25000],
        ]);

        $i4 = $this->makeItem($order, $workArea, $pid('P01'), 'HPL Taco + Frame Doff', 120, 180, 8,
            'Partisi modular antar workstation');
        $this->addMaterials($i4, [
            '376.00' => [ceil(120 * 180 * 8 / 15000), 280000],
            '121.00' => [ceil(120 * 180 * 8 / 20000), 420000],
            'B0261'  => [ceil((120 + 180) * 2 * 8 / 100) + 10, 15000],
            'B0109'  => [8 * 2, 85000],
        ]);

        // — Meeting Room ───────────────────────────────────────────────────────
        $meeting = $this->makeSection($order, 'Meeting Room');

        $i5 = $this->makeItem($order, $meeting, $pid('P03'), 'HPL Walnut Top + Kaki Besi', 300, 120, 1,
            'Meja konferensi 12 kursi');
        $this->addMaterials($i5, [
            '376.00' => [5, 280000],
            '178.00' => [2, 390000],
            'B0262'  => [14, 18000],
            '88.00'  => [6,  75000],
        ]);

        $i6 = $this->makeItem($order, $meeting, $pid('P04'), 'HPL Walnut + Kaca Pintu Geser', 120, 200, 2,
            'Display cabinet meeting room');
        $this->addMaterials($i6, [
            '376.00' => [4, 280000],
            '413.00' => [2, 220000],
            '178.00' => [2, 390000],
            'B0262'  => [14, 18000],
            '115.00' => [2 * 2, 25000],
        ]);

        // — Director's Office ──────────────────────────────────────────────────
        $director = $this->makeSection($order, "Director's Office");

        $i7 = $this->makeItem($order, $director, $pid('P05'), 'Duco Putih Glossy 4 Pintu Custom', 240, 240, 1,
            'Lemari kabinet direktur');
        $this->addMaterials($i7, [
            '376.00' => [8, 280000],
            '413.00' => [4, 220000],
            '156.00' => [3, 490000],
            'B0261'  => [30, 15000],
            '128.00' => [4, 120000],
        ]);

        $i8 = $this->makeItem($order, $director, $pid('P03'), 'HPL Splendor + Kaki Solid', 200, 90, 1,
            'Meja kerja direktur');
        $this->addMaterials($i8, [
            '376.00' => [4, 280000],
            '156.00' => [2, 490000],
            'B0262'  => [12, 18000],
            '88.00'  => [4,  75000],
        ]);
    }

    // =========================================================================
    // S6 — Cancelled Condo Order   status: cancelled
    //      Was urgent; customer pulled out 15 days in before SPK was issued.
    //      Items exist but no schedules — useful for "do not show in scheduler".
    // =========================================================================
    private function seedCancelledOrder(Carbon $today): void
    {
        $order = $this->makeOrder($this->customers[3], $today->copy()->subDays(15), [
            'status_id'           => 'cancelled',
            'is_urgent'           => true,
            'production_deadline' => $today->copy()->addWeeks(3),
        ]);

        $pid = fn (string $c) => $this->products->get($c)->id;

        $condo = $this->makeSection($order, 'Condo Unit');

        $i1 = $this->makeItem($order, $condo, $pid('P05'), 'Duco Putih Matte 3 Pintu', 150, 220, 2,
            'Lemari pakaian condo');
        $this->addMaterials($i1, [
            '376.00' => [5, 280000],
            '413.00' => [2, 220000],
            '123.00' => [2, 370000],
            'B0261'  => [20, 15000],
            '222.00' => [2 * 6, 65000],
        ]);

        $i2 = $this->makeItem($order, $condo, $pid('P07'), 'HPL Taco 186 AA', 60, 85, 3,
            'Kabinet dapur condo');
        $this->addMaterials($i2, [
            '376.00' => [1, 280000],
            '123.00' => [1, 370000],
            'B0261'  => [(int) ceil((60 + 85) * 2 * 3 / 100) + 4, 15000],
            '115.00' => [3 * 4, 25000],
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeOrder(Customer $customer, Carbon $orderDate, array $opts): ProductionOrder
    {
        $seq = str_pad($this->seq++, 3, '0', STR_PAD_LEFT);

        return ProductionOrder::create([
            'customer_id'         => $customer->id,
            'order_id'            => "{$orderDate->format('dm')} / PH-ICN / {$seq} / {$orderDate->format('Y')}",
            'nama_customer'       => $customer->nama,
            'alamat_customer'     => $customer->alamat,
            'tanggal_order'       => $orderDate,
            'status_id'           => $opts['status_id']           ?? 'new',
            'is_urgent'           => $opts['is_urgent']           ?? false,
            'production_deadline' => $opts['production_deadline'] ?? null,
            'production_start'    => $opts['production_start']    ?? null,
            'estimated_end'       => $opts['estimated_end']       ?? null,
            'material_eta'        => $opts['material_eta']        ?? null,
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
        return ProductionOrderItem::create([
            'production_order_id'              => $order->id,
            'production_order_item_section_id' => $section->id,
            'product_id'                       => $productId,
            'panjang'                          => $panjang,
            'tinggi'                           => $tinggi,
            'quantity'                         => $qty,
            'keterangan'                       => $note,
        ]);
    }

    private function addMaterials(ProductionOrderItem $item, array $mats, bool $isDeducted = false): void
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
                'is_deducted'             => $isDeducted,
            ]);
        }
    }

    private function makeSpk(ProductionOrder $order, Carbon $issuedDate): Spk
    {
        return Spk::create([
            'nomor_spk'           => "SPK/{$issuedDate->format('Y')}/" . str_pad($order->id, 3, '0', STR_PAD_LEFT),
            'tanggal_terbit'      => $issuedDate,
            'production_order_id' => $order->id,
            'assigned_factory'    => $this->factory?->id,
        ]);
    }

    private function makeSchedule(
        ProductionOrderItem $item,
        Station $station,
        Team $team,
        Carbon $startTime,
        Carbon $endTime,
        string $status,
        ?Carbon $actualStart,
        ?Carbon $actualEnd
    ): ProductionSchedule {
        return ProductionSchedule::create([
            'production_order_item_id' => $item->id,
            'station_id'               => $station->id,
            'team_id'                  => $team->id,
            'start_time'               => $startTime,
            'end_time'                 => $endTime,
            'status'                   => $status,
            'actual_start'             => $actualStart,
            'actual_end'               => $actualEnd,
        ]);
    }
}
