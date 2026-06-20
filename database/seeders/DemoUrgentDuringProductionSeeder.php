<?php

namespace Database\Seeders;

use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\Station;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 6 — Urgent Order Arrives Mid-Production
 *                    (Order Urgent Datang Saat Produksi Sudah Berjalan)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Membuktikan bahwa ketika order URGENT datang sementara sudah ada order lain
 *   yang berstatus on_going (produksi sedang berjalan), algoritma:
 *     1. Menyisipkan order urgent ke antrian dengan PRIORITAS TERTINGGI
 *        (dijadwalkan mulai secepatnya / hari ini juga).
 *     2. TIDAK mengganggu pekerjaan yang sedang/sudah dikerjakan
 *        (schedule berstatus in_progress & completed dipertahankan, tidak digeser).
 *     3. Mengurutkan order urgent MENDAHULUI sisa pekerjaan order lama yang
 *        belum dimulai (item backlog berstatus pending).
 *
 * DATA YANG DIBUAT:
 *
 *   [NORMAL · on_going] Order A — "Renovasi Rumah Tinggal"  (sudah produksi ~1,5 minggu)
 *     • Item 1 (Lemari)  : kayu SELESAI  → cat SEDANG DIKERJAKAN → acc menunggu
 *     • Item 2 (Meja)    : kayu SEDANG DIKERJAKAN → cat & acc menunggu
 *     • Item 3 (Partisi) : BELUM MULAI (semua stasiun pending)  ← backlog
 *     • Item 4 (Display) : BELUM MULAI (semua stasiun pending)  ← backlog
 *       → material sudah diterima & dipotong stok (is_deducted=true)
 *
 *   [URGENT · await_material] Order B — "Kitchen Set Apartemen"  (BARU MASUK hari ini)
 *     • Deadline 10 hari, is_urgent=true
 *     • Material sudah disiapkan & di-deduct (is_deducted=true) → SIAP DIKONFIRMASI
 *     • Belum punya schedule sama sekali
 *
 * MEKANISME YANG DIUJI:
 *   Saat material Order B dikonfirmasi tiba (PATCH /production/order/{id}/confirm-material-arrival):
 *     - confirmMaterialArrival() mengubah Order B → on_going lalu memanggil
 *       rescheduleAllPending().
 *     - rescheduleAllPending() HANYA menghapus & menjadwalkan ulang slot 'pending'
 *       (item backlog Order A + seluruh item Order B). Slot in_progress/completed
 *       Order A TIDAK tersentuh.
 *     - Di determineSequenceForFactory(), order is_urgent disortir paling depan
 *       (-999999), sehingga Order B menempati slot tim paling awal, mendahului
 *       item backlog Order A — meski Order A masuk sistem jauh lebih dulu.
 *
 * LANGKAH DEMO (step-by-step):
 *   1. php artisan db:seed --class=DemoUrgentDuringProductionSeeder
 *   2. Buka /production/ongoing → tunjukkan Order A sedang produksi
 *      (Item 1 cat berjalan, Item 2 kayu berjalan; Item 3 & 4 belum mulai).
 *   3. Buka /production/schedules → catat posisi item backlog Order A (Partisi, Display).
 *   4. Buka detail Order B (URGENT, await_material) → klik "Konfirmasi Material Tiba"
 *      (langsung berhasil karena material sudah deducted).
 *   5. Refresh /production/schedules →
 *      → TUNJUKKAN: Order B (URGENT) kini dijadwalkan mulai HARI INI / paling awal,
 *        MENDAHULUI sisa pekerjaan Order A yang belum mulai.
 *      → TUNJUKKAN: pekerjaan Order A yang sedang berjalan (cat Item 1, kayu Item 2)
 *        TIDAK berubah jadwalnya.
 *   6. Highlight: "Order urgent yang datang di tengah produksi langsung disisipkan
 *      dengan prioritas tertinggi tanpa mengacaukan pekerjaan yang sedang berjalan."
 *
 * CATATAN TEKNIS:
 *   - Status schedule kanonik: pending → in_progress → completed (bukan 'scheduled').
 *   - Hanya item dengan schedule SEMUA pending (atau tanpa schedule) yang dijadwalkan
 *     ulang; item yang punya schedule in_progress/completed dianggap "sudah jalan".
 *   - await_material → materialEta = now+14d; on_going → materialEta = now().
 *     Karena itu Order B WAJIB dikonfirmasi (→ on_going) agar bisa mulai hari ini.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoUrgentDuringProductionSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 151;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        // Stations & teams are needed to hand-craft the in-flight schedules
        // (DemoSeederTrait only loads materials/products/customers/factory).
        $kayu = Station::where('factory_location_id', $this->fac?->id)->where('nama_station', 'kayu')->first();
        $cat  = Station::where('factory_location_id', $this->fac?->id)->where('nama_station', 'cat')->first();
        $acc  = Station::where('factory_location_id', $this->fac?->id)->where('nama_station', 'acc')->first();

        if (!$kayu || !$cat || !$acc) {
            $this->command->error('Station kayu/cat/acc belum lengkap untuk pabrik ini. Jalankan StationSeeder & TeamSeeder dulu.');
            return;
        }

        $kayuTeams = Team::where('station_id', $kayu->id)->get()->values();
        $catTeams  = Team::where('station_id', $cat->id)->get()->values();
        $accTeams  = Team::where('station_id', $acc->id)->get()->values();

        if ($kayuTeams->isEmpty() || $catTeams->isEmpty() || $accTeams->isEmpty()) {
            $this->command->error('Setiap stasiun harus punya minimal 1 tim. Jalankan TeamSeeder dulu.');
            return;
        }

        $today = Carbon::today();
        // Absolute-day helper at 08:00 working start (relative to today).
        $t = fn (int $days, int $hour = 8) => $today->copy()->addDays($days)->setTime($hour, 0, 0);

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 6: Urgent Order Arrives Mid-Production ──');

        // ════════════════════════════════════════════════════════════════════
        // ORDER A — NORMAL, on_going (produksi sudah berjalan ~1,5 minggu)
        // ════════════════════════════════════════════════════════════════════
        // 4 item → base per item = 1440/4 = 360 menit.
        $oA = $this->mkOrder(0, $today->copy()->subDays(12), [
            'status'   => 'on_going',
            'deadline' => $today->copy()->addWeeks(3),
        ]);
        $oA->update([
            'production_start' => $t(-9),
            'estimated_end'    => $t(10),
        ]);

        // — Ruang Tamu ────────────────────────────────────────────────────────
        $sA1 = $this->mkSection($oA, 'Ruang Tamu');

        // Item 1 (Lemari) 200×220 qty1 → var=4.4×300=1320 → total 1680 (672/672/336)
        // kayu SELESAI · cat SEDANG DIKERJAKAN · acc menunggu
        $iA1 = $this->mkItem($oA, $sA1, 'P05', 'Duco Putih Glossy 4 Pintu', 200, 220, 1, 'Lemari pakaian — kayu selesai, cat berjalan');
        $this->mkMaterials($iA1, ['376.00' => [6, 280000], '413.00' => [3, 220000], '123.00' => [2, 370000], 'B0261' => [25, 15000], '222.00' => [4, 65000]], deducted: true);

        // Item 2 (Meja) 160×75 qty2 → var=2.4×300=720 → total 1080 (432/432/216)
        // kayu SEDANG DIKERJAKAN · cat & acc menunggu
        $iA2 = $this->mkItem($oA, $sA1, 'P03', 'HPL Walnut + Laci 3 Susun', 160, 75, 2, 'Meja konsol — kayu sedang dikerjakan');
        $this->mkMaterials($iA2, ['376.00' => [3, 280000], '121.00' => [1, 420000], 'B0261' => [12, 15000], '115.00' => [4, 25000]], deducted: true);

        // — Ruang Kerja ───────────────────────────────────────────────────────
        $sA2 = $this->mkSection($oA, 'Ruang Kerja');

        // Item 3 (Partisi) 120×200 qty2 → var=4.8×300=1440 → total 1800 (720/720/360) — BACKLOG
        $iA3 = $this->mkItem($oA, $sA2, 'P01', 'HPL Taco + Frame Doff', 120, 200, 2, 'Partisi — belum mulai (backlog)');
        $this->mkMaterials($iA3, ['376.00' => [4, 280000], '121.00' => [2, 420000], 'B0261' => [13, 15000], 'B0109' => [4, 85000]], deducted: true);

        // Item 4 (Display) 100×180 qty1 → var=1.8×300=540 → total 900 (360/360/180) — BACKLOG
        $iA4 = $this->mkItem($oA, $sA2, 'P04', 'HPL Splendor + Kaca Display', 100, 180, 1, 'Display cabinet — belum mulai (backlog)');
        $this->mkMaterials($iA4, ['376.00' => [3, 280000], '156.00' => [1, 490000], 'B0262' => [10, 18000], '115.00' => [4, 25000]], deducted: true);

        $this->mkSpk($oA, $today->copy()->subDays(11));

        // — Schedule in-flight Order A ─────────────────────────────────────────
        // Item 1: kayu completed → cat in_progress (berjalan now) → acc pending
        $this->mkSchedule($iA1, $kayu, $kayuTeams[0], $t(-9), $t(-5), 'completed',   $t(-9), $t(-5));
        $this->mkSchedule($iA1, $cat,  $catTeams[0],  $t(-4), $t(1),  'in_progress', $t(-4), null);
        $this->mkSchedule($iA1, $acc,  $accTeams[0],  $t(1),  $t(3),  'pending',     null,   null);

        // Item 2: kayu in_progress (berjalan now) → cat pending → acc pending
        $this->mkSchedule($iA2, $kayu, $kayuTeams[1], $t(-2), $t(2),  'in_progress', $t(-2), null);
        $this->mkSchedule($iA2, $cat,  $catTeams[1],  $t(2),  $t(4),  'pending',     null,   null);
        $this->mkSchedule($iA2, $acc,  $accTeams[1],  $t(4),  $t(5),  'pending',     null,   null);

        // Item 3: BACKLOG — semua pending (akan dijadwalkan ulang & dikalahkan urgent)
        $this->mkSchedule($iA3, $kayu, $kayuTeams[2], $t(2),  $t(6),  'pending', null, null);
        $this->mkSchedule($iA3, $cat,  $catTeams[2],  $t(6),  $t(9),  'pending', null, null);
        $this->mkSchedule($iA3, $acc,  $accTeams[2],  $t(9),  $t(10), 'pending', null, null);

        // Item 4: BACKLOG — semua pending
        $this->mkSchedule($iA4, $kayu, $kayuTeams[3 % $kayuTeams->count()], $t(3), $t(6), 'pending', null, null);
        $this->mkSchedule($iA4, $cat,  $catTeams[3 % $catTeams->count()],   $t(6), $t(8), 'pending', null, null);
        $this->mkSchedule($iA4, $acc,  $accTeams[2],                        $t(8), $t(9), 'pending', null, null);

        $this->command->line('  ✓ [NORMAL · on_going] Renovasi Rumah Tinggal — Item1 cat berjalan, Item2 kayu berjalan, Item3 & 4 backlog');

        // ════════════════════════════════════════════════════════════════════
        // ORDER B — URGENT, await_material (BARU MASUK, siap dikonfirmasi)
        // ════════════════════════════════════════════════════════════════════
        // 2 item → base per item = 1440/2 = 720 menit. Material sudah deducted.
        $oB = $this->mkOrder(1, $today->copy(), [
            'status'   => 'await_material',
            'urgent'   => true,
            'deadline' => $today->copy()->addDays(10),
        ]);
        $sB = $this->mkSection($oB, 'Kitchen Set');

        // Kitchen base 60×85 qty4 → var=2.04×300=612 → total 1332
        $iB1 = $this->mkItem($oB, $sB, 'P07', 'HPL Taco 186 AA Matte', 60, 85, 4, '[URGENT] Kabinet bawah kitchen set');
        $this->mkMaterials($iB1, ['376.00' => [max(1, (int) ceil(60 * 85 * 4 / 18000)), 280000], '413.00' => [1, 220000], '123.00' => [max(1, (int) ceil(60 * 85 * 4 / 22000)), 370000], 'B0261' => [(int) ceil((60 + 85) * 2 * 4 / 100) + 5, 15000], '115.00' => [16, 25000]], deducted: true);

        // Kitchen wall 60×70 qty3 → var=1.26×300=378 → total 1098
        $iB2 = $this->mkItem($oB, $sB, 'P08', 'HPL Taco 186 AA Matte', 60, 70, 3, '[URGENT] Kabinet atas kitchen set');
        $this->mkMaterials($iB2, ['376.00' => [max(1, (int) ceil(60 * 70 * 3 / 18000)), 280000], '413.00' => [1, 220000], '123.00' => [max(1, (int) ceil(60 * 70 * 3 / 22000)), 370000], 'B0261' => [(int) ceil((60 + 70) * 2 * 3 / 100) + 3, 15000], '115.00' => [6, 25000]], deducted: true);

        $this->mkSpk($oB, $today->copy());

        $this->command->line('  ✓ [URGENT · await_material] Kitchen Set Apartemen — deadline 10 HARI, material siap dikonfirmasi');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Scenario 6 seeded. LANGKAH DEMO:');
        $this->command->info('  1. /production/ongoing  → Order A sedang produksi (Item1 cat, Item2 kayu berjalan)');
        $this->command->info('  2. /production/schedules → catat posisi backlog Order A (Partisi, Display)');
        $this->command->info('  3. Detail Order B (URGENT) → klik "Konfirmasi Material Tiba"');
        $this->command->info('  4. Refresh /production/schedules');
        $this->command->info('  TUNJUKKAN: Order URGENT mulai paling awal, mendahului backlog Order A,');
        $this->command->info('             tanpa menggeser pekerjaan Order A yang sedang berjalan.');
        $this->command->info('═══════════════════════════════════════════════════════');
    }

    /**
     * Create a ProductionSchedule row directly (DemoSeederTrait has no schedule helper).
     */
    private function mkSchedule(
        ProductionOrderItem $item,
        Station $station,
        Team $team,
        Carbon $start,
        Carbon $end,
        string $status,
        ?Carbon $actualStart,
        ?Carbon $actualEnd
    ): ProductionSchedule {
        return ProductionSchedule::create([
            'production_order_item_id' => $item->id,
            'station_id'               => $station->id,
            'team_id'                  => $team->id,
            'start_time'               => $start,
            'end_time'                 => $end,
            'status'                   => $status,
            'actual_start'             => $actualStart,
            'actual_end'               => $actualEnd,
        ]);
    }
}
