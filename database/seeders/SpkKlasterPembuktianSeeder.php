<?php

namespace Database\Seeders;

use App\Models\FactoryLocation;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Services\Production\ProductionOrderService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeder klaster 3 SPK (2770 / 2785 / 2798) untuk PEMBUKTIAN via UI.
 *
 * Mereplika persis data uji `SpkClusterNov2025Test`, TAPI deadline dipasang
 * RELATIF terhadap now() agar partisi (EDD/critical), urutan, dan ketepatan
 * waktu tetap konsisten kapan pun seeder dijalankan:
 *   - SPK 2798 (mendesak) : deadline now + 15 hari
 *   - SPK 2770            : deadline now + 33 hari
 *   - SPK 2785            : deadline now + 35 hari
 *
 * Order ditinggalkan pada status `await_material` dengan material lengkap
 * (is_deducted = true). Untuk mereproduksi hasil test di UI:
 *   /production  → pilih ketiga order → "Konfirmasi Kedatangan Material" (batch)
 *   → status menjadi on_going → penjadwalan EDD berjalan → lihat /production/schedules
 *
 * Asumsi: tidak ada order lain di sistem (DB bersih) agar tim 6/6/3 kosong.
 *
 * Jalankan: php artisan db:seed --class=SpkKlasterPembuktianSeeder
 */
class SpkKlasterPembuktianSeeder extends Seeder
{
    public function run(): void
    {
        // Anti-duplikat
        if (ProductionOrder::where('order_id', 'like', '%KLASTER-BUKTI%')->exists()) {
            $this->command?->warn('Seeder klaster pembuktian sudah pernah dijalankan — dilewati.');
            return;
        }

        $product  = Product::first();
        $material  = Material::first();
        $factory  = FactoryLocation::first();
        if (!$product || !$material || !$factory) {
            $this->command?->error('Butuh minimal 1 Product, 1 Material, dan 1 FactoryLocation. Jalankan DatabaseSeeder dulu.');
            return;
        }

        $orderService = app(ProductionOrderService::class);
        $now = Carbon::now();

        // [deskripsi, panjang(cm), tinggi(cm), qty]
        $dataset = [
            [
                'spk' => '2770', 'customer' => 'Mr Andrew', 'location' => 'Villa Bali',
                'order_offset' => -4, 'deadline_offset' => 33,
                'items' => [
                    ['Bedroom 1 — Bedhead + Wardrobe + Divider TV + Meja kerja',      432, 240, 1],
                    ['Bedroom 1 — Bathroom (divider display + cabinet wastafel)',      180, 170, 1],
                    ['Bedroom 2 — Bedhead + Meja kerja + Meja TV + Wardrobe',          465, 240, 1],
                    ['Bedroom 2 — Bathroom',                                           180, 170, 1],
                    ['Bedrooms 3 & 4 — Full bedroom set (connecting door)',            455, 240, 2],
                    ['Bedrooms 3 & 4 — Bathrooms (x2)',                                180, 170, 2],
                    ['Bedroom 5 — Full bedroom set',                                   455, 240, 1],
                    ['Bedroom 5 — Bathroom',                                           180, 170, 1],
                    ['Bedroom 6 — Full bedroom set',                                   380, 240, 1],
                    ['Bedroom 6 — Bathroom',                                           180, 170, 1],
                    ['Penthouse — Minipantry + Bedroom + Sofa + Sliding door',         450, 300, 1],
                    ['Penthouse — Bathroom (wardrobe + meja rias + cabinet wastafel)', 250, 240, 1],
                ],
            ],
            [
                'spk' => '2785', 'customer' => 'Mr Yogi', 'location' => 'Slawi, Tegal',
                'order_offset' => -2, 'deadline_offset' => 35,
                'items' => [
                    ['Guest Bathroom Vanity cabinet (PVC veneer oak)',                  125,  70, 1],
                    ['Master Bathroom Vanity cabinet (PVC veneer oak)',                 120,  75, 1],
                    ['Ruang Duduk Wallpanel + Wallpanel plafon (lasercut backing)',      420, 290, 1],
                    ['Ruang Gym Cabinet dispenser + Wallpanel (veneer dark tea brown)',  368, 320, 1],
                    ['Girls Bedroom Meja belajar + Bench + Gate (veneer dark walnut)',   350, 298, 1],
                    ["Boy's Bathroom Vanity cabinet (PVC veneer)",                       138,  70, 1],
                    ["Girl's Bathroom Vanity cabinet (PVC veneer)",                      138,  70, 1],
                    ['Gym Storage + Bench + Wallpanel (downgrade to HPL)',               220, 380, 1],
                    ['Boys Bedroom Wallpanel Bedhead + Wallpanels (downgrade HPL)',      367, 298, 3],
                ],
            ],
            [
                'spk' => '2798', 'customer' => 'Mr Benny / Mrs Laurensia', 'location' => 'Surabaya',
                'order_offset' => 0, 'deadline_offset' => 15,
                'items' => [
                    ['Powder Room Kantor B1 — Meja wastafel + Full body mirror',  100, 185, 1],
                    ['Powder Room 1F — Meja wastafel + Full body mirror',           90, 200, 1],
                    ['Parents Bathroom 1F — Meja wastafel + Mirror + Ambalan',      90, 135, 1],
                    ['Master Bathroom 2F — 2x Meja wastafel + Pedestal + Mirror',  160,  85, 1],
                    ['Girl Bathroom 2F — Meja wastafel + Mirror (round)',           90, 135, 1],
                    ['Boy Bathroom 2F — Meja wastafel + Mirror + Shelving',        185, 185, 1],
                    ['Powder Room 3F — Meja wastafel solid surface + Mirror',      185,  50, 1],
                    ['Linen Room Pintu kamuflase + WIC Master upgrade (Formwell)',  90, 285, 2],
                ],
            ],
        ];

        foreach ($dataset as $spk) {
            $order = $orderService->create([
                'nama_customer'   => $spk['customer'],
                'alamat_customer' => $spk['location'],
                'nomor_telp'      => '08123456789',
                'tanggal_order'   => $now->copy()->addDays($spk['order_offset'])->toDateString(),
                'status_id'       => 'new',
                'is_urgent'       => false,
            ]);

            ProductionOrder::where('id', $order->id)->update([
                'production_deadline' => $now->copy()->addDays($spk['deadline_offset'])->setTime(17, 0, 0),
                'order_id'            => "{$spk['spk']} / KLASTER-BUKTI",
            ]);

            foreach ($spk['items'] as $idx => [$desc, $p, $h, $q]) {
                // Give every item its OWN product so each Gantt bar carries a
                // distinct, traceable label (e.g. "2770#1 Bedroom 1") instead of
                // all 29 items showing the same shared product name. Makes it
                // possible to follow a specific item across stations/teams and to
                // analyse early-finish reflow/assignment behaviour in the UI.
                $itemNo    = $idx + 1;
                $shortName = trim(\Illuminate\Support\Str::of($desc)->before('—')->before('(')->__toString());
                $shortName = \Illuminate\Support\Str::limit($shortName !== '' ? $shortName : $desc, 22, '');

                $itemProduct = Product::create([
                    'kode_product' => "BUKTI-{$spk['spk']}-{$itemNo}",
                    'nama_product' => "{$spk['spk']}#{$itemNo} {$shortName}",
                ]);

                $item = ProductionOrderItem::create([
                    'production_order_id' => $order->id,
                    'product_id'          => $itemProduct->id,
                    'panjang'             => $p,
                    'tinggi'              => $h,
                    'quantity'            => $q,
                    'keterangan'          => $desc,
                ]);
                ProductionOrderItemMaterial::create([
                    'production_order_item_id' => $item->id,
                    'material_id'              => $material->id,
                    'quantity'                 => 1,
                    'cost'                     => 0,
                    'is_deducted'              => true,
                ]);
            }

            $orderService->markAsAwaitMaterial($order->id, 'await_material');
            $this->command?->info("Order SPK {$spk['spk']} ({$spk['customer']}) dibuat — " . count($spk['items']) . ' item, await_material.');
        }

        $this->command?->info('Selesai. Di UI: pilih ketiga order → "Konfirmasi Kedatangan Material" (batch) untuk menjalankan penjadwalan EDD.');
    }
}
