<?php

namespace Database\Seeders;

use App\Models\FactoryLocation;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\Spk;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * SEEDER PENGUJIAN PENJADWALAN — Replika 10 SPK nyata (skenario Okt–Des 2026)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Mereplika SECARA PERSIS 10 order yang dipakai pada pengujian
 * `tests/Feature/SpkOctDecManualVsAlgoTest.php` (lihat PENGUJIAN_PENJADWALAN.md):
 * SPK 2532–2798, total 74 baris item, dimensi dalam cm.
 *
 * Order dibuat pada status `await_material`, SPK sudah terbit, dan setiap item
 * memiliki 1 material (is_deducted = true) — identik dengan kondisi awal pengujian.
 * `material_eta` semua order diset 5 Okt 2026 08:00 sebagai titik mulai bersama,
 * sama persis dengan anchor pengujian, agar hasil NEH+EDD dapat direproduksi.
 *
 * CARA PAKAI:
 *   1. Pastikan master data sudah ada:  php artisan db:seed
 *   2. Jalankan seeder ini:             php artisan db:seed --class=SpkPengujianPenjadwalanSeeder
 *   3. Buka halaman /production/pending → tampil 10 order
 *   4. Klik "Jalankan Penjadwalan" (memicu POST /production/order/run-scheduling)
 *   5. Buka /production/schedules untuk melihat hasil NEH+EDD
 *
 * Heuristik manual (proyek besar didahulukan/LPT) membuat SPK 2737 telat 14 hari;
 * algoritma NEH+EDD menjadwalkannya lebih awal sehingga 10/10 order tepat waktu.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class SpkPengujianPenjadwalanSeeder extends Seeder
{
    public function run(): void
    {
        $product  = Product::first();
        $material = Material::first();
        $factory  = FactoryLocation::first();

        if (!$product || !$material || !$factory) {
            $this->command->error('Master data belum lengkap (product/material/factory). Jalankan dulu: php artisan db:seed');
            return;
        }

        // Titik mulai bersama (anchor) — identik dengan pengujian.
        $materialEta = Carbon::parse('2026-10-05 08:00:00');

        $dataset = $this->dataset();

        $this->command->info('');
        $this->command->info('── Seeding Replika Pengujian Penjadwalan (10 SPK, Okt–Des 2026) ──');

        $createdCount = 0;
        $itemCount = 0;

        foreach ($dataset as $spk) {
            $orderId = "{$spk['spk_no']} / PH-ICN / 2026";

            if (ProductionOrder::where('order_id', $orderId)->exists()) {
                $this->command->warn("  ↷ SPK {$spk['spk_no']} sudah ada — dilewati (hindari duplikat).");
                continue;
            }

            $order = ProductionOrder::create([
                'order_id'            => $orderId,
                'nama_customer'       => $spk['customer'],
                'alamat_customer'     => $spk['location'],
                'tanggal_order'       => $spk['order_date'],
                'status_id'           => 'await_material',
                'is_urgent'           => false,
                'production_deadline' => $spk['deadline'],
                'material_eta'        => $materialEta,
            ]);

            // SPK terbit + factory assignment (wajib agar run-scheduling memproses order).
            Spk::create([
                'nomor_spk'           => 'SPK/UJI/2026/' . str_pad((string) $order->id, 4, '0', STR_PAD_LEFT),
                'tanggal_terbit'      => $spk['order_date'],
                'production_order_id' => $order->id,
                'assigned_factory'    => $factory->id,
            ]);

            foreach ($spk['items'] as [$desc, $panjang, $tinggi, $qty]) {
                $item = ProductionOrderItem::create([
                    'production_order_id' => $order->id,
                    'product_id'          => $product->id,
                    'panjang'             => $panjang,
                    'tinggi'              => $tinggi,
                    'quantity'            => $qty,
                    'keterangan'          => $desc,
                ]);

                // 1 material per item, sudah dikurangi dari stok (is_deducted = true),
                // identik dengan kondisi awal pengujian.
                ProductionOrderItemMaterial::create([
                    'production_order_item_id' => $item->id,
                    'material_id'              => $material->id,
                    'quantity'                 => 1,
                    'cost'                     => 0,
                    'is_deducted'              => true,
                ]);

                $itemCount++;
            }

            $createdCount++;
            $this->command->line(sprintf(
                '  ✓ SPK %s — %-26s %2d item  (deadline %s)',
                $spk['spk_no'],
                $spk['customer'],
                count($spk['items']),
                $spk['deadline']
            ));
        }

        $this->command->info('');
        $this->command->info(sprintf('Selesai: %d order, %d baris item dibuat (status await_material).', $createdCount, $itemCount));
        $this->command->info('Langkah berikut: /production/pending → "Jalankan Penjadwalan" → /production/schedules');
        $this->command->info('');
    }

    /**
     * Dataset 10 SPK nyata — identik dengan SpkOctDecManualVsAlgoTest.
     * Format item: [deskripsi, panjang(cm), tinggi(cm), qty].
     */
    private function dataset(): array
    {
        return [
            [
                'spk_no' => '2532', 'customer' => 'Mrs Lidya / Mr Donny', 'location' => 'Kupang NTT',
                'order_date' => '2026-10-01', 'deadline' => '2026-11-30',
                'items' => [
                    ['Wallpanel Ruang Tamu (Plywood duco PU komb grey mirror)',   385, 700, 1],
                    ['Wallpanel List Profil HMR Ruang Tamu',                      385, 300, 1],
                    ['Livingroom TV Cabinet + Wallpanel Kamuflase',               900, 300, 1],
                    ['Pantry Cabinet (Plywood duco PU komb alum gold)',           585, 300, 1],
                    ['Pantry Meja Island',                                        300,  90, 1],
                    ['Ruang Kerja Display Cabinet + Wallpanel HPL',               385, 300, 1],
                    ['Wet Kitchen Cabinet (2 sections)',                          450, 300, 2],
                    ['Guest Bedroom Wallpanel Bedhead + Wardrobe',                306, 300, 1],
                    ['Parents Bedroom Wallpanel Bedhead + TV Cabinet',            450, 300, 1],
                    ['Parents WIC Wardrobe + Cabinet Meja Rias + Credensa',       350, 300, 1],
                    ['Hall Wallpanel (Lantai 2)',                                  400, 300, 1],
                    ["Boy's Bedroom Display + Bedhead + Meja Belajar + Wardrobe", 285, 300, 1],
                    ["Girl's Bedroom Bedhead + Display + Meja Kerja + Wardrobes", 400, 300, 1],
                    ['Master Bedroom Bedhead + Credensa + WIC Wardrobes',         450, 300, 1],
                ],
            ],
            [
                'spk_no' => '2546', 'customer' => 'Mr Cahyadi', 'location' => 'Surabaya',
                'order_date' => '2026-10-01', 'deadline' => '2026-12-04',
                'items' => [
                    ['Basement Wallpanel PVC EX GAIA + backing',            515, 310, 1],
                    ['L1 Livingroom Wallpanel & Kamuflase (aksen duco PU)', 645, 300, 1],
                    ['L1 Shoes Cabinet + Meja TV (duco PU komb veneer)',    410, 500, 1],
                    ['L1 Pantry Wardrobe atas bawah (veneer white oak)',    455, 300, 1],
                    ['L1 Pantry Meja Island',                               197,  85, 1],
                    ['L1 Ruang Kerja Wallpanel + Drawers + Display',        480, 300, 1],
                    ['L1 Kitchen (cabinet kulkas + bawah + atas)',          500, 300, 1],
                    ['L1 Bedroom 1 (bedhead + wardrobe + wallpanel TV)',    635, 300, 1],
                    ['L1 Kamar Tamu (bedhead + divan + wardrobe)',          315, 300, 1],
                    ['L2 Livingroom Wallpanel & Kamuflase + TV Cabinet',   485, 300, 1],
                    ['L2 Master Bedroom bedhead + storage + TV Cabinet',   485, 300, 1],
                    ['L2 Master WIC (wardrobe + meja rias + storage)',      310, 300, 1],
                    ['L3 Multifunction Meja TV + Minibar + Display',       445, 300, 1],
                    ['L3 Ruang Gym + Bathrooms PVC items',                 500, 300, 1],
                ],
            ],
            [
                'spk_no' => '2685', 'customer' => 'Mrs Helena', 'location' => 'Surabaya',
                'order_date' => '2026-10-02', 'deadline' => '2026-11-27',
                'items' => [
                    ['Foyer Kabinet storage + Wallpanel backing',              200, 340, 1],
                    ['Foyer Wallpanel dinding storage + Pintu kamuflase',      385, 340, 1],
                    ['Foyer Wallpanel dinding kitchen + kamuflase WT/janitor', 495, 340, 1],
                    ['Working Room Lemari display + Wallpanel + Meja kerja',   250, 340, 1],
                    ['Pantry Kabinet atas bawah + kulkas + Island',            528, 320, 1],
                    ['Living Room Kabinet TV + railing + Lemari display',      483, 340, 1],
                    ['Master Bedroom bedhead + Meja TV + Meja kerja + WIC',   465, 320, 1],
                    ['Master Bathroom Meja wastafel + Lemari storage (PVC)',   235, 500, 1],
                ],
            ],
            [
                'spk_no' => '2770', 'customer' => 'Mr Andrew', 'location' => 'Villa Bali',
                'order_date' => '2026-10-02', 'deadline' => '2026-12-11',
                'items' => [
                    ['Bedroom 1 — Bedhead + Wardrobe + Divider TV + Meja kerja',     432, 240, 1],
                    ['Bedroom 1 — Bathroom (divider display + cabinet wastafel)',      180, 170, 1],
                    ['Bedroom 2 — Bedhead + Meja kerja + Meja TV + Wardrobe',        465, 240, 1],
                    ['Bedroom 2 — Bathroom',                                          180, 170, 1],
                    ['Bedrooms 3 & 4 — Full bedroom set (connecting door)',           455, 240, 2],
                    ['Bedrooms 3 & 4 — Bathrooms (x2)',                              180, 170, 2],
                    ['Bedroom 5 — Full bedroom set',                                  455, 240, 1],
                    ['Bedroom 5 — Bathroom',                                          180, 170, 1],
                    ['Bedroom 6 — Full bedroom set',                                  380, 240, 1],
                    ['Bedroom 6 — Bathroom',                                          180, 170, 1],
                    ['Penthouse — Minipantry + Bedroom + Sofa + Sliding door',        450, 300, 1],
                    ['Penthouse — Bathroom (wardrobe + meja rias + cabinet wastafel)', 250, 240, 1],
                ],
            ],
            [
                'spk_no' => '2785', 'customer' => 'Mr Yogi', 'location' => 'Slawi, Tegal',
                'order_date' => '2026-10-05', 'deadline' => '2026-12-04',
                'items' => [
                    ['Guest Bathroom Vanity cabinet (PVC veneer oak)',                    125,  70, 1],
                    ['Master Bathroom Vanity cabinet (PVC veneer oak)',                   120,  75, 1],
                    ['Ruang Duduk Wallpanel + Wallpanel plafon (lasercut backing)',        420, 290, 1],
                    ['Ruang Gym Cabinet dispenser + Wallpanel (veneer dark tea brown)',    368, 320, 1],
                    ['Girls Bedroom Meja belajar + Bench + Gate (veneer dark walnut)',     350, 298, 1],
                    ["Boy's Bathroom Vanity cabinet (PVC veneer)",                         138,  70, 1],
                    ["Girl's Bathroom Vanity cabinet (PVC veneer)",                        138,  70, 1],
                    ['Gym Storage + Bench + Wallpanel (downgrade to HPL)',                 220, 380, 1],
                    ['Boys Bedroom Wallpanel Bedhead + Wallpanels (downgrade HPL)',        367, 298, 3],
                ],
            ],
            [
                'spk_no' => '2768', 'customer' => 'Mr Andre', 'location' => 'Jember',
                'order_date' => '2026-10-06', 'deadline' => '2026-12-04',
                'items' => [
                    ['Kid Bedroom 1 — Meja Bonsai + Wardrobes + TV Cabinet', 533, 321, 1],
                    ['Kid Bedroom 2 — Wallpanel + Wardrobe + Sideboard',     300, 340, 1],
                    ['Kid Bedroom 3 — Wallpanel + Wardrobe + Night stand',   491, 348, 1],
                ],
            ],
            [
                'spk_no' => '2704', 'customer' => 'Mr Louis', 'location' => 'Mojokerto',
                'order_date' => '2026-10-09', 'deadline' => '2026-12-11',
                'items' => [
                    ['Diningroom/Pantry (tall cabinet + lower cabinet + upper)', 287, 300, 1],
                    ['Master WIC (wardrobe + 2 display tas + meja rias)',        405, 240, 1],
                ],
            ],
            [
                'spk_no' => '2798', 'customer' => 'Mr Benny / Mrs Laurensia', 'location' => 'Surabaya',
                'order_date' => '2026-10-12', 'deadline' => '2026-12-11',
                'items' => [
                    ['Powder Room Kantor B1 — Meja wastafel + Full body mirror', 100, 185, 1],
                    ['Powder Room 1F — Meja wastafel + Full body mirror',          90, 200, 1],
                    ['Parents Bathroom 1F — Meja wastafel + Mirror + Ambalan',     90, 135, 1],
                    ['Master Bathroom 2F — 2x Meja wastafel + Pedestal + Mirror', 160,  85, 1],
                    ['Girl Bathroom 2F — Meja wastafel + Mirror (round)',           90, 135, 1],
                    ['Boy Bathroom 2F — Meja wastafel + Mirror + Shelving',        185, 185, 1],
                    ['Powder Room 3F — Meja wastafel solid surface + Mirror',      185,  50, 1],
                    ['Linen Room Pintu kamuflase + WIC Master upgrade (Formwell)',   90, 285, 2],
                ],
            ],
            [
                'spk_no' => '2708', 'customer' => 'Mr Peter', 'location' => 'Surabaya',
                'order_date' => '2026-10-13', 'deadline' => '2026-12-14',
                'items' => [
                    ['Pantry Tall Cabinet + Cabinet atas bawah (komb alum black)', 315, 325, 1],
                    ['Pantry Meja Island',                                          280,  90, 1],
                ],
            ],
            [
                'spk_no' => '2737', 'customer' => 'Mr Santoso Wijono', 'location' => 'Lumajang',
                'order_date' => '2026-10-16', 'deadline' => '2026-11-20',
                'items' => [
                    ['Wet Kitchen Cabinet bawah + atas (sink area: PVC board)', 336,  92, 1],
                    ['Ruang Audio Display CD + Display piringan hitam',         219, 197, 2],
                ],
            ],
        ];
    }
}
