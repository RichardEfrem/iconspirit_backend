<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 1 — Urgent Priority (Prioritas Order Urgent)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Membuktikan bahwa order bertanda URGENT selalu didahulukan dalam
 *   penjadwalan, terlepas dari tanggal masuk order. Di antara order urgent,
 *   algoritma menggunakan EDD (Earliest Due Date).
 *
 * DATA YANG DIBUAT (semua status: await_material, SPK sudah terbit):
 *
 *   [NORMAL] Order 1 — Kamar Tidur Lengkap       deadline: 8 minggu  (~80 jam)
 *   [NORMAL] Order 2 — Fit-out Kantor             deadline: 12 minggu (~84 jam)
 *   [NORMAL] Order 3 — Display Butik              deadline: 10 minggu (~83 jam)
 *   [URGENT] Order 4 — Kitchen Set Apartemen      deadline: 14 hari   (~40 jam)
 *   [URGENT] Order 5 — Renovasi Dapur Mini        deadline: 7 HARI    (~34 jam)  ← paling ketat
 *
 * URUTAN JADWAL YANG DIHARAPKAN SETELAH RUN SCHEDULING:
 *   1. URGENT: Renovasi Dapur Mini  (deadline 7 hari — paling ketat di antara urgent)
 *   2. URGENT: Kitchen Set Apartemen (deadline 14 hari)
 *   3-7. NORMAL: diurutkan oleh algoritma NEH (bukan FIFO)
 *
 * LANGKAH DEMO:
 *   1. php artisan db:seed --class=DemoUrgentPrioritySeeder
 *   2. Buka /production/pending → tampilkan 5 order menunggu jadwal
 *   3. Klik "Jalankan Penjadwalan"
 *   4. Buka /production/schedules → tunjukkan bahwa 2 order URGENT
 *      dijadwalkan PERTAMA meski diterima belakangan dari order normal
 *   5. Highlight: "Meski order normal masuk lebih dulu, sistem otomatis
 *      mendahulukan order urgent. Di antara urgent, yang tenggat paling
 *      dekat (7 hari) dijadwalkan sebelum yang tenggat 14 hari."
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoUrgentPrioritySeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 101;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 1: Urgent Priority ──');

        // ── [NORMAL] Order 1: Kamar Tidur Lengkap — deadline 8 weeks ─────────
        // Submitted 10 days ago. Three items, total ~80 jam.
        // Per-item base = 1440/3 = 480 min each.
        //  • Wardrobe 200×220 qty 2 → var = 4.4×2×300 = 2640 → total 3120 min
        //  • Meja 150×75 qty 1    → var = 1.125×300 = 337  → total  817 min
        //  • Display 80×180 qty 1 → var = 1.44×300  = 432  → total  912 min
        $o1  = $this->mkOrder(1, $today->copy()->subDays(10), ['deadline' => $today->copy()->addWeeks(8)]);
        $s1  = $this->mkSection($o1, 'Kamar Tidur');
        $i   = $this->mkItem($o1, $s1, 'P05', 'Duco Putih Glossy 4 Pintu', 200, 220, 2, 'Lemari pakaian full height');
        $this->mkMaterials($i, ['376.00' => [6, 280000], '413.00' => [3, 220000], 'B0261' => [25, 15000], '115.00' => [8, 25000]]);
        $i   = $this->mkItem($o1, $s1, 'P03', 'HPL Walnut + Laci', 150, 75, 1, 'Meja belajar');
        $this->mkMaterials($i, ['376.00' => [2, 280000], '121.00' => [1, 420000], 'B0261' => [8, 15000]]);
        $i   = $this->mkItem($o1, $s1, 'P04', 'HPL Taco + Kaca Pintu', 80, 180, 1, 'Display cabinet buku');
        $this->mkMaterials($i, ['376.00' => [3, 280000], '413.00' => [1, 220000], 'B0261' => [10, 15000], '115.00' => [4, 25000]]);
        $this->mkSpk($o1, $today->copy()->subDays(9));
        $this->command->line('  ✓ [NORMAL] Kamar Tidur Lengkap — deadline 8 minggu  (~80 jam)');

        // ── [NORMAL] Order 2: Fit-out Kantor — deadline 12 weeks ─────────────
        // Submitted 7 days ago. Two items, total ~84 jam.
        // Per-item base = 1440/2 = 720 min.
        //  • Partisi 120×200 qty 4 → var = 9.6×300 = 2880 → total 3600 min
        //  • Meja 160×75 qty 2     → var = 2.4×300 = 720  → total 1440 min
        $o2  = $this->mkOrder(2, $today->copy()->subDays(7), ['deadline' => $today->copy()->addWeeks(12)]);
        $s2  = $this->mkSection($o2, 'Open Office');
        $i   = $this->mkItem($o2, $s2, 'P01', 'HPL Taco + Frame Huben Doff', 120, 200, 4, 'Partisi modular antar workstation');
        $this->mkMaterials($i, ['376.00' => [ceil(120*200*4/15000), 280000], '121.00' => [ceil(120*200*4/20000), 420000], 'B0261' => [ceil((120+200)*2*4/100)+6, 15000], 'B0109' => [8, 85000]]);
        $i   = $this->mkItem($o2, $s2, 'P03', 'HPL Taco 186 AA + Laci 3 Susun', 160, 75, 2, 'Meja kerja karyawan');
        $this->mkMaterials($i, ['376.00' => [2, 280000], '121.00' => [1, 420000], 'B0261' => [ceil((160+75)*2*2/100)+4, 15000], '115.00' => [4, 25000]]);
        $this->mkSpk($o2, $today->copy()->subDays(6));
        $this->command->line('  ✓ [NORMAL] Fit-out Kantor — deadline 12 minggu (~84 jam)');

        // ── [NORMAL] Order 3: Display Butik — deadline 10 weeks ──────────────
        // Submitted 5 days ago. Two items, total ~83 jam.
        // Per-item base = 720 min.
        //  • Display 100×200 qty 3  → var = 6.0×300 = 1800 → total 2520 min
        //  • Wallpanel 120×240 qty 2 → var = 5.76×300=1728 → total 2448 min
        $o3  = $this->mkOrder(3, $today->copy()->subDays(5), ['deadline' => $today->copy()->addWeeks(10)]);
        $s3  = $this->mkSection($o3, 'Show Room');
        $i   = $this->mkItem($o3, $s3, 'P04', 'HPL Splendor + Kaca Display', 100, 200, 3, 'Display cabinet utama');
        $this->mkMaterials($i, ['376.00' => [ceil(100*200*3/15000), 280000], '156.00' => [ceil(100*200*3/22000), 490000], 'B0262' => [ceil((100+200)*2*3/100)+5, 18000], '115.00' => [6, 25000]]);
        $i   = $this->mkItem($o3, $s3, 'P02', 'Duco Putih Glossy', 120, 240, 2, 'Wallpanel branding area');
        $this->mkMaterials($i, ['376.00' => [ceil(120*240*2/15000), 280000], '123.00' => [ceil(120*240*2/20000), 370000], 'B0261' => [ceil((120+240)*2*2/100)+5, 15000]]);
        $this->mkSpk($o3, $today->copy()->subDays(4));
        $this->command->line('  ✓ [NORMAL] Display Butik — deadline 10 minggu (~83 jam)');

        // ── [URGENT] Order 4: Kitchen Set Apartemen — deadline 14 days ───────
        // Submitted 2 days ago. Two items, total ~40 jam.
        // Per-item base = 720 min.
        //  • Kitchen base 60×85 qty 4 → var = 2.04×300 = 612 → total 1332 min
        //  • Kitchen wall 60×70 qty 3 → var = 1.26×300 = 378 → total 1098 min
        $o4  = $this->mkOrder(0, $today->copy()->subDays(2), [
            'urgent'   => true,
            'deadline' => $today->copy()->addDays(14),
        ]);
        $s4  = $this->mkSection($o4, 'Kitchen Set');
        $i   = $this->mkItem($o4, $s4, 'P07', 'HPL Taco 186 AA Matte', 60, 85, 4, 'Kabinet bawah kitchen set');
        $this->mkMaterials($i, ['376.00' => [max(1, (int)ceil(60*85*4/18000)), 280000], '413.00' => [1, 220000], '123.00' => [max(1, (int)ceil(60*85*4/22000)), 370000], 'B0261' => [(int)ceil((60+85)*2*4/100)+5, 15000], '115.00' => [16, 25000]]);
        $i   = $this->mkItem($o4, $s4, 'P08', 'HPL Taco 186 AA Matte', 60, 70, 3, 'Kabinet atas kitchen set');
        $this->mkMaterials($i, ['376.00' => [max(1, (int)ceil(60*70*3/18000)), 280000], '413.00' => [1, 220000], '123.00' => [max(1, (int)ceil(60*70*3/22000)), 370000], 'B0261' => [(int)ceil((60+70)*2*3/100)+3, 15000], '115.00' => [6, 25000]]);
        $this->mkSpk($o4, $today->copy()->subDay());
        $this->command->line('  ✓ [URGENT] Kitchen Set Apartemen — deadline 14 HARI (~40 jam)');

        // ── [URGENT] Order 5: Renovasi Dapur Mini — deadline 7 days ──────────
        // Submitted today. One item, total ~34 jam.
        // base = 1440 (single item), var = 0.68×3×300 = 612 → total 2052 min
        // TIGHTEST deadline among all orders — must be first in schedule.
        $o5  = $this->mkOrder(4, $today->copy(), [
            'urgent'   => true,
            'deadline' => $today->copy()->addDays(7),
        ]);
        $s5  = $this->mkSection($o5, 'Dapur Mini');
        $i   = $this->mkItem($o5, $s5, 'P07', 'HPL Putih Matte', 80, 85, 3, 'Kabinet bawah dapur mini');
        $this->mkMaterials($i, ['376.00' => [max(1, (int)ceil(80*85*3/18000)), 280000], '413.00' => [1, 220000], '123.00' => [max(1, (int)ceil(80*85*3/22000)), 370000], 'B0261' => [(int)ceil((80+85)*2*3/100)+4, 15000], '115.00' => [12, 25000]]);
        $this->mkSpk($o5, $today->copy());
        $this->command->line('  ✓ [URGENT] Renovasi Dapur Mini — deadline 7 HARI (~34 jam) ← harus PERTAMA');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Scenario 1 seeded. LANGKAH DEMO:');
        $this->command->info('  1. Buka /production/pending');
        $this->command->info('  2. Klik "Jalankan Penjadwalan"');
        $this->command->info('  3. Buka /production/schedules');
        $this->command->info('  TUNJUKKAN: Order Urgent dijadwalkan PERTAMA!');
        $this->command->info('  Urutan: Dapur Mini(7h) → Kitchen Set(14h) → Normal via NEH');
        $this->command->info('═══════════════════════════════════════════════════════');
    }
}
