<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 2 — NEH Makespan Optimization (Optimasi Penjadwalan NEH)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Membuktikan bahwa algoritma NEH (Nawaz-Enscore-Ham) menghasilkan urutan
 *   penjadwalan yang lebih efisien dibanding FIFO (urutan masuk order).
 *   NEH mengurangi makespan total dengan meminimalkan waktu tunggu antar stasiun.
 *
 * DATA YANG DIBUAT — 5 order NORMAL, semua tenggat 16 minggu, SPK sudah terbit.
 * Order diterima dari yang paling kecil ke paling besar (urutan FIFO):
 *
 *   Order 1 [KECIL]    — 1 display  60×80  qty 1   → ±26 jam   (masuk pertama)
 *   Order 2 [SEDANG]   — 2 kabinet  80×85  qty 3   → ±53 jam
 *   Order 3 [MENENGAH] — 4 partisi  120×200 qty 2  → ±73 jam
 *   Order 4 [BESAR]    — 4 wallpanel 180×240 qty 4 → ±162 jam
 *   Order 5 [RAKSASA]  — 3 wardrobe 200×220 qty 3  → ±213 jam  (masuk terakhir)
 *
 * URUTAN FIFO (tidak efisien):
 *   KECIL → SEDANG → MENENGAH → BESAR → RAKSASA
 *   Pekerjaan kecil di awal membuat stasiun Cat & Acc menganggur lama
 *   saat menunggu stasiun Kayu menyelesaikan pekerjaan besar berikutnya.
 *
 * URUTAN NEH (efisien — yang dihasilkan sistem):
 *   RAKSASA → BESAR → MENENGAH → SEDANG → KECIL
 *   Pekerjaan terbesar dikerjakan duluan, sehingga stasiun Cat & Acc
 *   langsung terisi dan makespan total lebih pendek.
 *
 * LANGKAH DEMO:
 *   1. php artisan db:seed --class=DemoNehOptimizationSeeder
 *   2. Tunjukkan bahwa Order 1 (KECIL) adalah order yang masuk PERTAMA
 *   3. Buka /production/pending — tampilkan 5 order
 *   4. Klik "Jalankan Penjadwalan"
 *   5. Buka /production/schedules
 *   6. TUNJUKKAN: Order RAKSASA (masuk terakhir!) dijadwalkan PERTAMA
 *   7. Highlight: "NEH memindahkan pekerjaan terbesar ke depan untuk
 *      meminimalkan waktu idle di semua stasiun — bukan sekadar FIFO."
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoNehOptimizationSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 111;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();
        $deadline = $today->copy()->addWeeks(16);

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 2: NEH Optimization ──');

        // ── Order 1: KECIL — masuk paling awal (tanggal_order = 8 hari lalu) ──
        // base = 1440 (1 item), area = 60×80/10000 = 0.48m²
        // var = 0.48 × 1 × 300 = 144 min → total ≈ 1584 min (26.4 jam)
        // NEH akan menempatkan ini TERAKHIR karena durasi paling pendek.
        $o1  = $this->mkOrder(0, $today->copy()->subDays(8), ['deadline' => $deadline]);
        $s1  = $this->mkSection($o1, 'Pajangan');
        $i   = $this->mkItem($o1, $s1, 'P04', 'HPL Natural Matte', 60, 80, 1, '[NEH Demo] Job KECIL — masuk pertama, dijadwalkan terakhir');
        $this->mkMaterials($i, ['376.00' => [1, 280000], '121.00' => [1, 420000], 'B0261' => [8, 15000], '115.00' => [2, 25000]]);
        $this->mkSpk($o1, $today->copy()->subDays(7));
        $this->command->line('  ✓ Order 1 [KECIL]    ~26 jam  — masuk pertama  (seq 111)');

        // ── Order 2: SEDANG — masuk ke-2 ─────────────────────────────────────
        // 2 items: base = 720 each
        //  • Kabinet bawah 80×85 qty 3 → var = 2.04×300 = 612 → total 1332 min
        //  • Kabinet atas  80×70 qty 2 → var = 1.12×300 = 336 → total 1056 min
        // Total order: 2388 min ≈ 39.8 jam
        $o2  = $this->mkOrder(1, $today->copy()->subDays(6), ['deadline' => $deadline]);
        $s2  = $this->mkSection($o2, 'Dapur Kecil');
        $i   = $this->mkItem($o2, $s2, 'P07', 'HPL Taco 186 AA', 80, 85, 3, '[NEH Demo] Job SEDANG item-1');
        $this->mkMaterials($i, ['376.00' => [max(1,(int)ceil(80*85*3/15000)), 280000], '413.00' => [1, 220000], '121.00' => [1, 420000], 'B0261' => [(int)ceil((80+85)*2*3/100)+4, 15000], '115.00' => [12, 25000]]);
        $i   = $this->mkItem($o2, $s2, 'P08', 'HPL Taco 186 AA', 80, 70, 2, '[NEH Demo] Job SEDANG item-2');
        $this->mkMaterials($i, ['376.00' => [max(1,(int)ceil(80*70*2/15000)), 280000], '413.00' => [1, 220000], '121.00' => [1, 420000], 'B0261' => [(int)ceil((80+70)*2*2/100)+3, 15000], '115.00' => [4, 25000]]);
        $this->mkSpk($o2, $today->copy()->subDays(5));
        $this->command->line('  ✓ Order 2 [SEDANG]   ~40 jam  — masuk ke-2     (seq 112)');

        // ── Order 3: MENENGAH — masuk ke-3 ───────────────────────────────────
        // 2 items: base = 720 each
        //  • Partisi 120×200 qty 2 → var = 4.8×300 = 1440 → total 2160 min
        //  • Meja 160×75 qty 3     → var = 3.6×300 = 1080 → total 1800 min
        // Total order: 3960 min ≈ 66 jam
        $o3  = $this->mkOrder(2, $today->copy()->subDays(4), ['deadline' => $deadline]);
        $s3  = $this->mkSection($o3, 'Ruang Kerja');
        $i   = $this->mkItem($o3, $s3, 'P01', 'HPL Taco + Frame Huben', 120, 200, 2, '[NEH Demo] Job MENENGAH item-1');
        $this->mkMaterials($i, ['376.00' => [ceil(120*200*2/15000), 280000], '121.00' => [ceil(120*200*2/20000), 420000], 'B0261' => [(int)ceil((120+200)*2*2/100)+4, 15000], 'B0109' => [4, 85000]]);
        $i   = $this->mkItem($o3, $s3, 'P03', 'HPL Walnut + Laci', 160, 75, 3, '[NEH Demo] Job MENENGAH item-2');
        $this->mkMaterials($i, ['376.00' => [ceil(160*75*3/15000), 280000], '121.00' => [1, 420000], 'B0261' => [(int)ceil((160+75)*2*3/100)+5, 15000], '115.00' => [6, 25000]]);
        $this->mkSpk($o3, $today->copy()->subDays(3));
        $this->command->line('  ✓ Order 3 [MENENGAH] ~66 jam  — masuk ke-3     (seq 113)');

        // ── Order 4: BESAR — masuk ke-4 ──────────────────────────────────────
        // 1 item: base = 1440
        //  • Wallpanel 180×240 qty 4 → var = (180×240/10000)×4×300 = 4.32×4×300 = 5184 → total 6624 min ≈ 110 jam
        $o4  = $this->mkOrder(3, $today->copy()->subDays(2), ['deadline' => $deadline]);
        $s4  = $this->mkSection($o4, 'Ruang Tamu');
        $i   = $this->mkItem($o4, $s4, 'P02', 'Duco Putih Matte Full Panel', 180, 240, 4, '[NEH Demo] Job BESAR — masuk ke-4, dijadwalkan ke-2');
        $this->mkMaterials($i, ['376.00' => [ceil(180*240*4/15000), 280000], '123.00' => [ceil(180*240*4/20000), 370000], 'B0261' => [(int)ceil((180+240)*2*4/100)+8, 15000]]);
        $this->mkSpk($o4, $today->copy()->subDay());
        $this->command->line('  ✓ Order 4 [BESAR]    ~110 jam — masuk ke-4     (seq 114)');

        // ── Order 5: RAKSASA — masuk paling terakhir ─────────────────────────
        // 1 item: base = 1440
        //  • Wardrobe 200×220 qty 3 → var = (200×220/10000)×3×300 = 4.4×3×300 = 3960 → total 5400 min ≈ 90 jam
        // Plus 2nd item: Wardrobe 180×220 qty 2 → base=720, var=3.96×2×300=2376, total=3096 min ≈ 51.6 jam
        // Grand total: 5400+3096 = 8496 min ≈ 141.6 jam
        // NEH akan menempatkan item RAKSASA ini PERTAMA karena durasi terpanjang.
        $o5  = $this->mkOrder(4, $today->copy()->subDay(), ['deadline' => $deadline]);
        $s5  = $this->mkSection($o5, 'Master Bedroom');
        $i   = $this->mkItem($o5, $s5, 'P05', 'Duco Putih Glossy 4 Pintu', 200, 220, 3, '[NEH Demo] Job RAKSASA item-1 — masuk terakhir, dijadwalkan PERTAMA');
        $this->mkMaterials($i, ['376.00' => [ceil(200*220*3/15000), 280000], '413.00' => [4, 220000], '178.00' => [2, 390000], 'B0261' => [(int)ceil((200+220)*2*3/100)+8, 15000], '222.00' => [12, 65000]]);
        $i   = $this->mkItem($o5, $s5, 'P05', 'Duco Putih Glossy 4 Pintu', 180, 220, 2, '[NEH Demo] Job RAKSASA item-2');
        $this->mkMaterials($i, ['376.00' => [ceil(180*220*2/15000), 280000], '413.00' => [3, 220000], '178.00' => [1, 390000], 'B0261' => [(int)ceil((180+220)*2*2/100)+6, 15000], '222.00' => [8, 65000]]);
        $this->mkSpk($o5, $today->copy());
        $this->command->line('  ✓ Order 5 [RAKSASA]  ~142 jam — masuk terakhir (seq 115) ← NEH jadwalkan PERTAMA');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Scenario 2 seeded. LANGKAH DEMO:');
        $this->command->info('  1. Buka /production/pending');
        $this->command->info('     Perhatikan: Order KECIL (26 jam) masuk pertama,');
        $this->command->info('     Order RAKSASA (142 jam) masuk terakhir.');
        $this->command->info('  2. Klik "Jalankan Penjadwalan"');
        $this->command->info('  3. Buka /production/schedules');
        $this->command->info('  TUNJUKKAN: Order RAKSASA dijadwalkan PERTAMA!');
        $this->command->info('  FIFO: Kecil→Sedang→Menengah→Besar→Raksasa (tidak efisien)');
        $this->command->info('  NEH:  Raksasa→Besar→Menengah→Sedang→Kecil  (makespan optimal)');
        $this->command->info('═══════════════════════════════════════════════════════');
    }
}
