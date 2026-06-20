<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 5 — Full Pipeline (Semua Tier Prioritas dalam Satu Demo)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Skenario paling komprehensif untuk presentasi. Menunjukkan keempat tier
 *   prioritas algoritma bekerja secara bersamaan dalam satu run penjadwalan.
 *
 * EMPAT TIER PRIORITAS SISTEM:
 *   Tier 1 — URGENT   : is_urgent=true → EDD di antara sesama urgent
 *   Tier 2 — ON_GOING : material sudah tiba → langsung ke produksi
 *                        (tidak ada di sini — ditunjukkan di Scenario 4)
 *   Tier 3 — CRITICAL : item_deadline ≤ 7 hari → EDD, tanpa NEH
 *   Tier 4 — NORMAL   : NEH (minimasi makespan)
 *
 * DATA YANG DIBUAT (semua await_material, SPK sudah terbit):
 *
 *   [TIER 1 - URGENT]   Order 1 — Dapur Apartemen    deadline:  7 HARI  ~34 jam
 *   [TIER 1 - URGENT]   Order 2 — Kitchen Mini       deadline: 12 HARI  ~40 jam
 *   [TIER 3 - CRITICAL] Order 3 — Display Toko Kecil deadline:  9 HARI  ~26 jam
 *   [TIER 4 - NORMAL]   Order 4 — Renovasi Penuh     deadline: 14 minggu ~168 jam
 *   [TIER 4 - NORMAL]   Order 5 — Fit-out Kantor     deadline: 10 minggu  ~66 jam
 *   [TIER 4 - NORMAL]   Order 6 — Set Kamar Hotel    deadline:  8 minggu  ~40 jam
 *
 * URUTAN JADWAL YANG DIHARAPKAN:
 *   1. URGENT — Dapur Apartemen  (7 hari, tier 1, deadline terkecil)
 *   2. URGENT — Kitchen Mini     (12 hari, tier 1)
 *   3. CRITICAL — Display Kecil  (item_deadline ≈ 5.9 hari, tier 3)
 *   4-6. NORMAL via NEH:
 *        Renovasi Penuh (168 jam) → Fit-out Kantor (66 jam) → Kamar Hotel (40 jam)
 *
 * LANGKAH DEMO:
 *   1. php artisan db:seed --class=DemoFullPipelineSeeder
 *   2. Buka /production/pending → 6 order tampil
 *      Tunjukkan: Order 1 & 2 = URGENT, Order 3 = deadline 9 hari,
 *                 Order 4 deadline 14 minggu tapi pekerjaan terbesar.
 *   3. Klik "Jalankan Penjadwalan"
 *   4. Buka /production/schedules
 *   5. TUNJUKKAN URUTAN:
 *      a) Urgent pertama (7 hari → 12 hari)
 *      b) Display kecil setelah urgent meski lebih kecil dari renovasi penuh
 *      c) Renovasi penuh (terbesar) menjadi pertama di antara normal
 *   6. "Satu klik — algoritma menangani 4 tier prioritas sekaligus!"
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoFullPipelineSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 141;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 5: Full Pipeline (All 4 Tiers) ──');

        // ── [TIER 1 - URGENT] Order 1: Dapur Apartemen — deadline 7 DAYS ─────
        // is_urgent=true, 1 item: base=1440
        // Kitchen base 80×85 qty 3 → area=2.04m², var=612, total=2052 min ≈ 34 jam
        // EDD rank among urgents: 7 hari → PALING PERTAMA
        $o1  = $this->mkOrder(0, $today->copy()->subDays(2), [
            'urgent'   => true,
            'deadline' => $today->copy()->addDays(7),
        ]);
        $s1  = $this->mkSection($o1, 'Dapur');
        $i   = $this->mkItem($o1, $s1, 'P07', 'HPL Putih Matte', 80, 85, 3, '[FULL Demo] TIER 1 URGENT — deadline 7 hari → jadwal ke-1');
        $this->mkMaterials($i, [
            '376.00' => [max(1,(int)ceil(80*85*3/18000)), 280000],
            '413.00' => [1, 220000],
            '123.00' => [max(1,(int)ceil(80*85*3/22000)), 370000],
            'B0261'  => [(int)ceil((80+85)*2*3/100)+4, 15000],
            '115.00' => [12, 25000],
        ]);
        $this->mkSpk($o1, $today->copy()->subDay());
        $this->command->line('  ✓ [TIER 1-URGENT]   Dapur Apartemen — deadline  7 HARI  (~34 jam) → ke-1');

        // ── [TIER 1 - URGENT] Order 2: Kitchen Mini — deadline 12 DAYS ───────
        // is_urgent=true, 2 items: base=720 each
        // Kitchen base 60×85 qty 4 → total 1332 min
        // Kitchen wall 60×70 qty 3 → total 1098 min
        // Total order ≈ 40 jam. EDD rank: 12 hari → ke-2 di antara urgent
        $o2  = $this->mkOrder(1, $today->copy()->subDays(3), [
            'urgent'   => true,
            'deadline' => $today->copy()->addDays(12),
        ]);
        $s2  = $this->mkSection($o2, 'Mini Kitchen');
        $i   = $this->mkItem($o2, $s2, 'P07', 'HPL Taco 186 AA Duco Matte', 60, 85, 4, '[FULL Demo] TIER 1 URGENT — deadline 12 hari → jadwal ke-2');
        $this->mkMaterials($i, [
            '376.00' => [max(1,(int)ceil(60*85*4/18000)), 280000],
            '413.00' => [1, 220000],
            '123.00' => [max(1,(int)ceil(60*85*4/22000)), 370000],
            'B0261'  => [(int)ceil((60+85)*2*4/100)+5, 15000],
            '115.00' => [16, 25000],
        ]);
        $i   = $this->mkItem($o2, $s2, 'P08', 'HPL Taco 186 AA Duco Matte', 60, 70, 3, '[FULL Demo] TIER 1 URGENT item-2');
        $this->mkMaterials($i, [
            '376.00' => [max(1,(int)ceil(60*70*3/18000)), 280000],
            '413.00' => [1, 220000],
            '123.00' => [max(1,(int)ceil(60*70*3/22000)), 370000],
            'B0261'  => [(int)ceil((60+70)*2*3/100)+3, 15000],
            '115.00' => [6, 25000],
        ]);
        $this->mkSpk($o2, $today->copy()->subDays(2));
        $this->command->line('  ✓ [TIER 1-URGENT]   Kitchen Mini — deadline 12 HARI  (~40 jam) → ke-2');

        // ── [TIER 3 - CRITICAL] Order 3: Display Toko — deadline 9 DAYS ──────
        // Non-urgent, 1 item: base=1440
        // Display 60×80 qty 1 → area=0.48m², var=144, total=1584 min ≈ 26 jam
        // item_deadline = today+9 − 1.1hari − 2hari ≈ today+5.9 hari → CRITICAL ≤7 ✓
        // Meski lebih kecil dari semua normal, masuk tier 3 karena critical window
        $o3  = $this->mkOrder(2, $today->copy()->subDay(), [
            'deadline' => $today->copy()->addDays(9),
        ]);
        $s3  = $this->mkSection($o3, 'Etalase');
        $i   = $this->mkItem($o3, $s3, 'P04', 'HPL Natural Matte', 60, 80, 1, '[FULL Demo] TIER 3 CRITICAL — item_deadline ≈ 5.9 hari → jadwal ke-3');
        $this->mkMaterials($i, [
            '376.00' => [1, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [8, 15000],
            '115.00' => [2, 25000],
        ]);
        $this->mkSpk($o3, $today->copy());
        $this->command->line('  ✓ [TIER 3-CRITICAL] Display Toko — deadline  9 HARI  (~26 jam) → ke-3');
        $this->command->line('                       item_deadline ≈ 5.9 hari (masuk critical window ≤7)');

        // ── [TIER 4 - NORMAL] Order 4: Renovasi Penuh — deadline 14 weeks ────
        // Large job, 1 item: base=1440
        // Wallpanel 200×240 qty 6 → area=28.8m², var=8640, total=10080 min ≈ 168 jam
        // item_deadline ≈ today+98 − 7hari − 2hari ≈ today+89hari → NORMAL
        // Terbesar di antara normal → NEH tempatkan PERTAMA di tier normal
        $o4  = $this->mkOrder(3, $today->copy()->subDays(4), [
            'deadline' => $today->copy()->addWeeks(14),
        ]);
        $s4  = $this->mkSection($o4, 'Ruang Utama');
        $i   = $this->mkItem($o4, $s4, 'P02', 'Duco Putih Matte Full Panel', 200, 240, 6, '[FULL Demo] TIER 4 NORMAL — TERBESAR → NEH jadwalkan pertama di antara normal');
        $this->mkMaterials($i, [
            '376.00' => [ceil(200*240*6/15000), 280000],
            '123.00' => [ceil(200*240*6/20000), 370000],
            'B0261'  => [(int)ceil((200+240)*2*6/100)+10, 15000],
        ]);
        $this->mkSpk($o4, $today->copy()->subDays(3));
        $this->command->line('  ✓ [TIER 4-NORMAL]   Renovasi Penuh — deadline 14 MINGGU (~168 jam) → NEH ke-1 (tier 4)');

        // ── [TIER 4 - NORMAL] Order 5: Fit-out Kantor — deadline 10 weeks ────
        // Medium job, 2 items: base=720 each
        // Partisi 120×200 qty 2 → total 2160 min; Meja 160×75 qty 3 → total 1800 min
        // Total ≈ 66 jam → NEH menempatkan setelah order besar
        $o5  = $this->mkOrder(4, $today->copy()->subDays(5), [
            'deadline' => $today->copy()->addWeeks(10),
        ]);
        $s5  = $this->mkSection($o5, 'Kantor');
        $i   = $this->mkItem($o5, $s5, 'P01', 'HPL Taco + Frame Huben', 120, 200, 2, '[FULL Demo] TIER 4 NORMAL — menengah');
        $this->mkMaterials($i, [
            '376.00' => [ceil(120*200*2/15000), 280000],
            '121.00' => [ceil(120*200*2/20000), 420000],
            'B0261'  => [(int)ceil((120+200)*2*2/100)+4, 15000],
            'B0109'  => [4, 85000],
        ]);
        $i   = $this->mkItem($o5, $s5, 'P03', 'HPL Walnut + Laci 3 Susun', 160, 75, 3, '[FULL Demo] TIER 4 NORMAL item-2');
        $this->mkMaterials($i, [
            '376.00' => [ceil(160*75*3/15000), 280000],
            '121.00' => [1, 420000],
            'B0261'  => [(int)ceil((160+75)*2*3/100)+5, 15000],
            '115.00' => [6, 25000],
        ]);
        $this->mkSpk($o5, $today->copy()->subDays(4));
        $this->command->line('  ✓ [TIER 4-NORMAL]   Fit-out Kantor — deadline 10 MINGGU (~66 jam)  → NEH ke-2 (tier 4)');

        // ── [TIER 4 - NORMAL] Order 6: Kamar Hotel — deadline 8 weeks ────────
        // Small-medium job, 2 items: base=720 each
        // Wardrobe 160×200 qty 1 → var=3.2×300=960, total=1680 min
        // Meja 140×75 qty 2     → var=2.1×300=630, total=1350 min
        // Total ≈ 50 jam → NEH: terkecil di tier normal → jadwal terakhir
        $o6  = $this->mkOrder(0, $today->copy()->subDays(6), [
            'deadline' => $today->copy()->addWeeks(8),
        ]);
        $s6  = $this->mkSection($o6, 'Kamar Hotel');
        $i   = $this->mkItem($o6, $s6, 'P05', 'HPL Taco 868 LU 2 Pintu', 160, 200, 1, '[FULL Demo] TIER 4 NORMAL — terkecil di antara normal → NEH terakhir');
        $this->mkMaterials($i, [
            '376.00' => [ceil(160*200*1/15000), 280000],
            '413.00' => [2, 220000],
            '121.00' => [ceil(160*200*1/20000), 420000],
            'B0261'  => [(int)ceil((160+200)*2*1/100)+6, 15000],
            '222.00' => [4, 65000],
        ]);
        $i   = $this->mkItem($o6, $s6, 'P03', 'HPL Walnut Matte + Laci', 140, 75, 2, '[FULL Demo] TIER 4 NORMAL item-2');
        $this->mkMaterials($i, [
            '376.00' => [2, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [(int)ceil((140+75)*2*2/100)+4, 15000],
            '115.00' => [4, 25000],
        ]);
        $this->mkSpk($o6, $today->copy()->subDays(5));
        $this->command->line('  ✓ [TIER 4-NORMAL]   Kamar Hotel — deadline  8 MINGGU (~50 jam)  → NEH ke-3 (tier 4)');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->info('Scenario 5 seeded (Full Pipeline). LANGKAH DEMO:');
        $this->command->info('');
        $this->command->info('  1. Buka /production/pending → 6 order menunggu jadwal');
        $this->command->info('     Tunjukkan: 2 URGENT, 1 HAMPIR KADALUARSA, 3 NORMAL');
        $this->command->info('');
        $this->command->info('  2. Klik "Jalankan Penjadwalan" (1 klik!)');
        $this->command->info('');
        $this->command->info('  3. Buka /production/schedules');
        $this->command->info('     URUTAN YANG DIHARAPKAN:');
        $this->command->info('     ① URGENT — Dapur Apartemen  (7 hari)   ← deadline terkecil urgent');
        $this->command->info('     ② URGENT — Kitchen Mini     (12 hari)  ← urgent ke-2 by EDD');
        $this->command->info('     ③ CRITICAL— Display Toko    (9 hari)   ← critical window, meski kecil');
        $this->command->info('     ④ NEH    — Renovasi Penuh   (14 mgg)  ← terbesar → NEH utamakan');
        $this->command->info('     ⑤ NEH    — Fit-out Kantor   (10 mgg)');
        $this->command->info('     ⑥ NEH    — Kamar Hotel      (8 mgg)');
        $this->command->info('');
        $this->command->info('  PESAN: "Satu klik, 4 tier prioritas, 6 order — semuanya otomatis!"');
        $this->command->info('═══════════════════════════════════════════════════════════════');
    }
}
