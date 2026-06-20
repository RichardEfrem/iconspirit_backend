<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 3 — Critical Deadline Window (Tenggat Kritis)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Membuktikan bahwa order dengan tenggat dalam ≤7 hari (critical window)
 *   mendapat prioritas EDD — mendahului order normal meski pekerjaan order
 *   kritis jauh lebih kecil dan seharusnya kalah dalam seleksi NEH.
 *
 * LOGIKA CRITICAL WINDOW:
 *   Sistem menghitung 'item_deadline' = order_deadline − waktu_proses − 2_hari_buffer.
 *   Jika item_deadline ≤ 7 hari dari sekarang → masuk tier CRITICAL (EDD saja, tanpa NEH).
 *   Order normal → tier NORMAL (diproses NEH berdasarkan makespan).
 *
 * DATA YANG DIBUAT (semua await_material, SPK terbit):
 *
 *   [RAKSASA-NORMAL] Order 1 — 6 wallpanel 200×240 qty 6 → ±233 jam
 *     deadline: 8 minggu → item_deadline ≈ 8wk − 9.7hari − 2hari ≈ +44 hari → NORMAL
 *     Pekerjaan terbesar → NEH akan tempatkan ini PERTAMA di antara order normal.
 *
 *   [KRITIS !!!]     Order 2 — 1 display  60×80  qty 1 → ±26 jam
 *     deadline: +9 HARI → item_deadline ≈ 9 − 1.1 − 2 ≈ +5.9 hari → CRITICAL ≤7 ✓
 *     Pekerjaan terkecil → seharusnya kalah dari NEH — tapi tenggat menyelamatkannya!
 *
 *   [SEDANG-NORMAL]  Order 3 — 2 partisi + 2 meja → ±66 jam
 *     deadline: 6 minggu → item_deadline ≈ 42 − 2.75 − 2 ≈ +37 hari → NORMAL
 *
 * URUTAN JADWAL YANG DIHARAPKAN:
 *   1st → CRITICAL: Display Kecil (tenggat 9 hari, meski hanya 26 jam kerja!)
 *   2nd → NORMAL NEH: Wallpanel Raksasa (233 jam, NEH tempatkan pertama di antara normal)
 *   3rd → NORMAL NEH: Partisi + Meja (66 jam)
 *
 * PESAN DEMO:
 *   "Lihat — display kecil ini (26 jam kerja) dijadwalkan SEBELUM
 *    wallpanel raksasa (233 jam)! Bukan karena ukurannya, tapi karena
 *    tenggat 9 harinya masuk critical window ≤7 hari.
 *    Sistem secara otomatis memprioritaskan order yang hampir terlambat."
 *
 * LANGKAH DEMO:
 *   1. php artisan db:seed --class=DemoCriticalWindowSeeder
 *   2. Buka /production/pending → tunjukkan tanggal tenggat masing-masing
 *   3. Klik "Jalankan Penjadwalan"
 *   4. Buka /production/schedules → Order Kritis dijadwalkan PERTAMA
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoCriticalWindowSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 121;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 3: Critical Deadline Window ──');

        // ── [RAKSASA-NORMAL] Order 1: Wallpanel Restoran — deadline 8 weeks ──
        // 1 item: base = 1440, area = (200×240/10000)×6 = 28.8m²
        // var = 28.8 × 300 = 8640, total = 10080 min ≈ 168 jam
        // item_deadline = today+56 − (10080/60/24=7hari) − 2hari ≈ today+47hari → NORMAL
        $o1  = $this->mkOrder(2, $today->copy()->subDays(5), [
            'deadline' => $today->copy()->addWeeks(8),
        ]);
        $s1  = $this->mkSection($o1, 'Dining Area');
        $i   = $this->mkItem($o1, $s1, 'P02', 'Duco Putih Matte Large Panel', 200, 240, 6, '[KRITIS Demo] Order RAKSASA — NEH tempatkan pertama di antara NORMAL');
        $this->mkMaterials($i, [
            '376.00' => [ceil(200*240*6/15000), 280000],
            '123.00' => [ceil(200*240*6/20000), 370000],
            'B0261'  => [(int)ceil((200+240)*2*6/100)+10, 15000],
        ]);
        $this->mkSpk($o1, $today->copy()->subDays(4));
        $this->command->line('  ✓ [RAKSASA-NORMAL] Wallpanel Restoran — deadline 8 MINGGU (~168 jam)');
        $this->command->line('                     item_deadline ≈ +47 hari → tier: NORMAL');

        // ── [KRITIS!!!] Order 2: Display Miniatur — deadline 9 days ──────────
        // 1 item: base = 1440, area = (60×80/10000)×1 = 0.48m²
        // var = 0.48 × 300 = 144, total = 1584 min ≈ 26.4 jam
        // item_deadline = today+9 − (1584/60/24=1.1hari) − 2hari ≈ today+5.9hari → CRITICAL ✓
        $o2  = $this->mkOrder(0, $today->copy()->subDays(1), [
            'deadline' => $today->copy()->addDays(9),
        ]);
        $s2  = $this->mkSection($o2, 'Etalase Kecil');
        $i   = $this->mkItem($o2, $s2, 'P04', 'HPL Natural Matte', 60, 80, 1, '[KRITIS Demo] Order KRITIS — kecil tapi tenggat mendesak!');
        $this->mkMaterials($i, [
            '376.00' => [1, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [8, 15000],
            '115.00' => [2, 25000],
        ]);
        $this->mkSpk($o2, $today->copy());
        $this->command->line('  ✓ [KRITIS!!!]     Display Miniatur — deadline 9 HARI  (~26 jam)  ← DIJADWALKAN PERTAMA');
        $this->command->line('                     item_deadline ≈ +5.9 hari → tier: CRITICAL ≤7 hari');

        // ── [SEDANG-NORMAL] Order 3: Ruang Kerja — deadline 6 weeks ──────────
        // 2 items: base = 720 each
        //  • Partisi 120×200 qty 2 → var = 4.8×300 = 1440 → total 2160 min
        //  • Meja 160×75 qty 3     → var = 3.6×300 = 1080 → total 1800 min
        // item_deadline ≈ today+42 − 1.5-2.5hari − 2hari ≈ +37-38hari → NORMAL
        $o3  = $this->mkOrder(1, $today->copy()->subDays(3), [
            'deadline' => $today->copy()->addWeeks(6),
        ]);
        $s3  = $this->mkSection($o3, 'Ruang Kerja');
        $i   = $this->mkItem($o3, $s3, 'P01', 'HPL Taco + Frame Huben Doff', 120, 200, 2, '[KRITIS Demo] Order SEDANG item-1');
        $this->mkMaterials($i, [
            '376.00' => [ceil(120*200*2/15000), 280000],
            '121.00' => [ceil(120*200*2/20000), 420000],
            'B0261'  => [(int)ceil((120+200)*2*2/100)+4, 15000],
            'B0109'  => [4, 85000],
        ]);
        $i   = $this->mkItem($o3, $s3, 'P03', 'HPL Walnut Natural', 160, 75, 3, '[KRITIS Demo] Order SEDANG item-2');
        $this->mkMaterials($i, [
            '376.00' => [ceil(160*75*3/15000), 280000],
            '121.00' => [1, 420000],
            'B0261'  => [(int)ceil((160+75)*2*3/100)+5, 15000],
            '115.00' => [6, 25000],
        ]);
        $this->mkSpk($o3, $today->copy()->subDays(2));
        $this->command->line('  ✓ [SEDANG-NORMAL]  Ruang Kerja — deadline 6 MINGGU  (~66 jam)');
        $this->command->line('                     item_deadline ≈ +37 hari → tier: NORMAL');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Scenario 3 seeded. LANGKAH DEMO:');
        $this->command->info('  1. Buka /production/pending');
        $this->command->info('     Order Display hanya ~26 jam, tapi tenggat 9 hari!');
        $this->command->info('  2. Klik "Jalankan Penjadwalan"');
        $this->command->info('  3. Buka /production/schedules');
        $this->command->info('  TUNJUKKAN: Display Kecil (26 jam) dijadwalkan VOR');
        $this->command->info('             Wallpanel Raksasa (168 jam)!');
        $this->command->info('  WHY: item_deadline Display ≈ 5.9 hari → masuk Critical Window (≤7 hari)');
        $this->command->info('       Sistem: Critical EDD > Normal NEH');
        $this->command->info('═══════════════════════════════════════════════════════');
    }
}
