<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO SCENARIO 4 — Early Material Arrival Reschedule (Material Datang Awal)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TUJUAN:
 *   Membuktikan bahwa ketika material dikonfirmasi tiba (lebih awal dari perkiraan),
 *   sistem otomatis menjadwalkan ulang seluruh antrian — order yang materialnya
 *   sudah ada langsung masuk stasiun produksi hari ini.
 *
 * SKENARIO:
 *   Order A (Material Siap)   — kamar tidur, material_eta today+3
 *     → Material sudah ditandai DITERIMA di seeder ini (is_deducted=true)
 *     → Siap dikonfirmasi LANGSUNG dari UI tanpa langkah tambahan
 *
 *   Order B (Material Belum)  — ruang kantor, material_eta today+25
 *     → Material belum diterima (is_deducted=false)
 *     → Tidak bisa dikonfirmasi dulu
 *
 * EFEK YANG DITUNJUKKAN:
 *   Sebelum konfirmasi: kedua order dijadwalkan mulai ~14 hari dari sekarang
 *                       (sistem menunggu material tiba)
 *   Setelah konfirmasi Order A: Order A di-reschedule mulai HARI INI
 *                                Order B tetap ~14 hari dari sekarang
 *
 * LANGKAH DEMO (step-by-step):
 *   1. php artisan db:seed --class=DemoMaterialArrivalSeeder
 *   2. Klik "Jalankan Penjadwalan" di /production/pending
 *   3. Buka /production/schedules → TUNJUKKAN kedua order mulai ~2 minggu ke depan
 *   4. Buka detail Order A (material_eta today+3)
 *      → Klik "Konfirmasi Material Tiba" (langsung berhasil karena material sudah deducted)
 *   5. Refresh /production/schedules
 *      → TUNJUKKAN: Order A sekarang mulai HARI INI / minggu ini!
 *      → Order B tetap di posisi ~2 minggu ke depan
 *   6. Highlight: "Material datang lebih awal → sistem langsung reschedule
 *      seluruh antrian secara otomatis. Tidak perlu mengatur ulang manual."
 *
 * CATATAN TEKNIS:
 *   - status_id kedua order: await_material (siap dijadwalkan)
 *   - Order A: semua material is_deducted=true (siap dikonfirmasi)
 *   - Order B: semua material is_deducted=false (masih menunggu)
 *   - Setelah konfirmasi, Order A berubah ke status on_going
 *   - dispatchSequence: on_going → materialEta=now(); await_material → materialEta=now+14d
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoMaterialArrivalSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 131;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();

        $this->command->info('');
        $this->command->info('── Seeding Demo Scenario 4: Material Arrival Reschedule ──');

        // ── Order A: Material SUDAH SIAP (is_deducted = true) ─────────────────
        // Kamar tidur lengkap: wardrobe + meja + display
        // Material ETA today+3 (hampir tiba) — tapi di seeder sudah ditandai deducted.
        // Setelah scheduling awal → slot mulai 14 hari ke depan.
        // Setelah "Konfirmasi Material Tiba" → slot pindah ke hari ini.
        $oA  = $this->mkOrder(0, $today->copy()->subDays(7), [
            'deadline' => $today->copy()->addWeeks(6),
            'eta'      => $today->copy()->addDays(3),
        ]);
        $sA  = $this->mkSection($oA, 'Kamar Tidur');

        // Wardrobe 160×220 qty 1 (2 items total → base = 720)
        // area = 3.52m², var = 3.52×300 = 1056, total = 1776 min
        $iA1 = $this->mkItem($oA, $sA, 'P05', 'Duco Putih Matte 2 Pintu', 160, 220, 1, '[ETA Demo] Order A — material SUDAH SIAP, siap dikonfirmasi');
        $this->mkMaterials($iA1, [
            '376.00' => [4, 280000],
            '413.00' => [2, 220000],
            '121.00' => [2, 420000],
            'B0261'  => [18, 15000],
            '222.00' => [4, 65000],
        ], deducted: true); // ← material sudah diterima dan dipotong dari stok

        // Meja 140×75 qty 1 (2 items → base = 720)
        // area = 1.05m², var = 315, total = 1035 min
        $iA2 = $this->mkItem($oA, $sA, 'P03', 'HPL Walnut Natural', 140, 75, 1, '[ETA Demo] Order A item-2');
        $this->mkMaterials($iA2, [
            '376.00' => [2, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [8, 15000],
        ], deducted: true); // ← semua material deducted

        $this->mkSpk($oA, $today->copy()->subDays(6));

        $this->command->line('  ✓ Order A [MATERIAL SIAP]   — Kamar Tidur, deadline 6 minggu');
        $this->command->line('    material_eta = today+3, is_deducted=true → SIAP DIKONFIRMASI');

        // ── Order B: Material BELUM TIBA (is_deducted = false) ────────────────
        // Ruang kantor: partisi + meja + display cabinet
        // Material ETA today+25 (masih jauh) — tidak bisa dikonfirmasi dulu.
        // Setelah scheduling → slot tetap di ~14 hari ke depan sepanjang demo.
        $oB  = $this->mkOrder(1, $today->copy()->subDays(3), [
            'deadline' => $today->copy()->addWeeks(8),
            'eta'      => $today->copy()->addDays(25),
        ]);
        $sB  = $this->mkSection($oB, 'Open Office');

        // Partisi 120×180 qty 3 (2 items → base = 720)
        // area = (120×180/10000)×3 = 6.48m², var = 6.48×300 = 1944, total = 2664 min
        $iB1 = $this->mkItem($oB, $sB, 'P01', 'HPL Taco + Frame Doff', 120, 180, 3, '[ETA Demo] Order B — material BELUM TIBA (eta today+25)');
        $this->mkMaterials($iB1, [
            '376.00' => [ceil(120*180*3/15000), 280000],
            '121.00' => [ceil(120*180*3/20000), 420000],
            'B0261'  => [(int)ceil((120+180)*2*3/100)+5, 15000],
            'B0109'  => [6, 85000],
        ], deducted: false); // ← material belum diterima

        // Meja 160×75 qty 4 (2 items → base = 720)
        // area = (160×75/10000)×4 = 4.8m², var = 1440, total = 2160 min
        $iB2 = $this->mkItem($oB, $sB, 'P03', 'HPL Taco 186 AA + Laci', 160, 75, 4, '[ETA Demo] Order B item-2');
        $this->mkMaterials($iB2, [
            '376.00' => [ceil(160*75*4/15000), 280000],
            '121.00' => [1, 420000],
            'B0261'  => [(int)ceil((160+75)*2*4/100)+6, 15000],
            '115.00' => [8, 25000],
        ], deducted: false);

        $this->mkSpk($oB, $today->copy()->subDays(2));

        $this->command->line('  ✓ Order B [MATERIAL BELUM] — Ruang Kantor, deadline 8 minggu');
        $this->command->line('    material_eta = today+25, is_deducted=false → belum bisa dikonfirmasi');

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Scenario 4 seeded. LANGKAH DEMO:');
        $this->command->info('  STEP 1: Klik "Jalankan Penjadwalan" di /production/pending');
        $this->command->info('          → Kedua order mendapat slot ~14 hari ke depan');
        $this->command->info('          → Buka /production/schedules, tunjukkan jadwal jauh ke depan');
        $this->command->info('');
        $this->command->info('  STEP 2: Buka detail Order A (Material Siap)');
        $this->command->info('          → Klik "Konfirmasi Material Tiba"');
        $this->command->info('          → Berhasil langsung (material sudah di-deduct)');
        $this->command->info('');
        $this->command->info('  STEP 3: Refresh /production/schedules');
        $this->command->info('          → Order A sekarang mulai HARI INI (on_going)');
        $this->command->info('          → Order B tetap ~14 hari ke depan (await_material)');
        $this->command->info('  PESAN: Material datang lebih awal → reschedule otomatis!');
        $this->command->info('═══════════════════════════════════════════════════════');
    }
}
