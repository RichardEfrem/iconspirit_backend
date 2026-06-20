<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DEMO — Simple Past Order, Material Already Arrived (Menunggu Material, SIAP)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Satu order sederhana untuk menguji alur konfirmasi material:
 *   - status_id   = await_material  → tampil di halaman "Menunggu" (/production/pending)
 *   - tanggal_order di masa lalu (today-10) → "past order"
 *   - material_eta di masa lalu (today-2)   → material seharusnya sudah tiba
 *   - semua material is_deducted = true      → "sudah dikonfirmasi tiba" / SIAP
 *
 * Efek: order langsung muncul sebagai READY (bisa di-"Konfirmasi Material Tiba"
 * atau dipilih untuk batch-confirm) tanpa langkah tambahan.
 *
 * Jalankan:  php artisan db:seed --class=DemoReadyPastOrderSeeder
 * ═══════════════════════════════════════════════════════════════════════════
 */
class DemoReadyPastOrderSeeder extends Seeder
{
    use DemoSeederTrait;

    protected int $seq = 161;

    public function run(): void
    {
        if (!$this->boot()) {
            return;
        }

        $today = Carbon::today();

        $this->command->info('');
        $this->command->info('── Seeding: Simple Past Order (material already arrived) ──');

        // Past-dated await_material order; material ETA already passed and deducted.
        $order = $this->mkOrder(0, $today->copy()->subDays(10), [
            'status'   => 'await_material',
            'deadline' => $today->copy()->addWeeks(5),
            'eta'      => $today->copy()->subDays(2), // material sudah lewat ETA = sudah tiba
        ]);

        $section = $this->mkSection($order, 'Kamar Tidur');

        $item = $this->mkItem(
            $order,
            $section,
            'P03',
            'HPL Walnut Natural',
            140,
            75,
            1,
            '[Demo] Past order — material SUDAH TIBA & dikonfirmasi (siap dijadwalkan)'
        );

        // is_deducted = true → material dianggap sudah diterima/dipotong dari stok.
        $this->mkMaterials($item, [
            '376.00' => [2, 280000],
            '121.00' => [1, 420000],
            'B0261'  => [8, 15000],
        ], deducted: true);

        $this->mkSpk($order, $today->copy()->subDays(9));

        $this->command->line("  ✓ Order {$order->order_id}");
        $this->command->line('    status=await_material, tanggal_order=today-10, eta=today-2');
        $this->command->line('    semua material is_deducted=true → SIAP dikonfirmasi di /production/pending');
        $this->command->info('');
    }
}
