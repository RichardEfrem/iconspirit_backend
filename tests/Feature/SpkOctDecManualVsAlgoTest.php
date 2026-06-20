<?php

/**
 * SPK Skenario Okt–Des 2026: Jadwal Manual Kepala Operasional vs NEH+EDD
 *
 * Baseline = JADWAL MANUAL seorang kepala operasional. Ia menjadwalkan berdasarkan
 * pengalaman & nilai kontrak: proyek besar/flagship didahulukan (heuristik
 * "largest processing time first" / LPT), tanpa analisis tenggat secara sistematis.
 * Pendekatan ini wajar di lapangan namun TIDAK OPTIMAL terhadap deadline — satu
 * order kecil bertenggat lebih awal (SPK 2737) terdorong ke akhir antrean dan TELAT.
 *
 * Lalu data yang sama dijadwalkan ulang oleh ALGORITMA program (NEH+EDD) pada
 * sumber daya identik (6 tim kayu / 6 tim cat / 3 tim acc). Algoritma menaikkan
 * prioritas order bertenggat dekat sehingga SELURUH 10 order selesai tepat waktu.
 *
 * Variabel yang dikendalikan: rumus durasi, jumlah tim, kalender kerja, dan titik
 * mulai (Senin 5 Okt 2026) identik untuk kedua metode. Satu-satunya pembeda adalah
 * STRATEGI PENGURUTAN job — sehingga selisih hasil murni karena kualitas algoritma.
 *
 * Seluruh dimensi dalam cm (dikonversi dari mm pada dokumen SPK asli).
 */

use App\Models\FactoryLocation;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\ProductionSchedule;
use App\Models\Station;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SPK Okt-Des 2026: jadwal manual kepala operasional vs NEH+EDD', function () {

    $this->seed();
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00'));

    config([
        'production.minutes_per_m2'          => 300,
        'production.base_production_minutes'  => 1440,
        'production.cm2_per_m2'              => 10000,
        'production.work_minutes_per_day'    => 540,
        'production.station_split'           => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2],
        'production.buffer_days'             => 2,
        'production.critical_window_days'    => 7,
        'production.calendar_penalty'        => 1.4,
        'production.tardiness_weight'        => 2.0,
        'production.handoff_buffer_minutes'  => 0,
        'production.order_deadline_months'   => 2,
    ]);

    // ── Working-hours helpers (mirror SchedulingService private methods) ──────
    $advance = function (Carbon $t): Carbon {
        $t = $t->copy();
        if ($t->isWeekend()) return $t->next(Carbon::MONDAY)->setTime(8, 0, 0);
        if ($t->format('H:i:s') < '08:00:00') return $t->setTime(8, 0, 0);
        if ($t->format('H:i:s') >= '17:00:00') {
            $t->addDay()->setTime(8, 0, 0);
            if ($t->isWeekend()) $t->next(Carbon::MONDAY)->setTime(8, 0, 0);
        }
        return $t;
    };

    $addMins = function (Carbon $start, float $mins) use ($advance): Carbon {
        $c = $advance($start->copy());
        $rem = $mins;
        while ($rem > 0) {
            $eod = $c->copy()->setTime(17, 0, 0);
            $avail = $c->diffInMinutes($eod);
            if ($rem <= $avail) { $c->addMinutes((int) $rem); $rem = 0; }
            else { $rem -= $avail; $c->addDay()->setTime(8, 0, 0); $c = $advance($c); }
        }
        return $c;
    };

    $nextAvail = function (Carbon $end) use ($advance): Carbon {
        if ($end->format('H:i:s') >= '15:00:00') {
            return $advance($end->copy()->addDay()->setTime(8, 0, 0));
        }
        return $end->copy();
    };

    $maxC = fn(Carbon $a, Carbon $b): Carbon => $a->gt($b) ? $a->copy() : $b->copy();

    // Duration formula (identik dengan SchedulingService::calculateJobMinutes)
    $calcMins = function (float $p, float $h, int $qty, int $n): array {
        $total = (1440.0 / max(1, $n)) + (($p * $h) / 10000.0) * $qty * 300.0;
        return ['kayu' => $total * 0.4, 'cat' => $total * 0.4, 'acc' => $total * 0.2, 'total' => $total];
    };

    $product      = Product::first();
    $material     = Material::first();
    $orderService = app(ProductionOrderService::class);
    $scheduling   = app(SchedulingService::class);

    // ── SPK dataset ───────────────────────────────────────────────────────────
    // ARRAY ORDER = URUTAN MANUAL kepala operasional (proyek terbesar didahulukan / LPT).
    // Order kecil SPK 2737 (deadline paling awal: 20 Nov) sengaja ditaruh PALING AKHIR
    // oleh keputusan manual → akan TELAT. Inilah satu-satunya order yang meleset.
    $spkDataset = [

        // #1 — SPK 2532 — Mr Donny — Kupang (XL, ~62.500 mnt) — flagship, didahulukan
        [
            'spk_no' => '2532', 'customer' => 'Mrs Lidya / Mr Donny', 'location' => 'Kupang NTT',
            'order_date' => '2026-10-01', 'deadline' => '2026-11-30', 'is_urgent' => false,
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

        // #2 — SPK 2546 — Mr Cahyadi — Surabaya (XL, ~60.000 mnt)
        [
            'spk_no' => '2546', 'customer' => 'Mr Cahyadi', 'location' => 'Surabaya',
            'order_date' => '2026-10-01', 'deadline' => '2026-12-04', 'is_urgent' => false,
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

        // #3 — SPK 2685 — Mrs Helena — Surabaya (L, ~33.000 mnt)
        [
            'spk_no' => '2685', 'customer' => 'Mrs Helena', 'location' => 'Surabaya',
            'order_date' => '2026-10-02', 'deadline' => '2026-11-27', 'is_urgent' => false,
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

        // #4 — SPK 2770 — Mr Andrew — Villa Bali (L, ~32.000 mnt)
        [
            'spk_no' => '2770', 'customer' => 'Mr Andrew', 'location' => 'Villa Bali',
            'order_date' => '2026-10-02', 'deadline' => '2026-12-11', 'is_urgent' => false,
            'items' => [
                ['Bedroom 1 — Bedhead + Wardrobe + Divider TV + Meja kerja',     432, 240, 1],
                ['Bedroom 1 — Bathroom (divider display + cabinet wastafel)',      180, 170, 1],
                ['Bedroom 2 — Bedhead + Meja kerja + Meja TV + Wardrobe',        465, 240, 1],
                ['Bedroom 2 — Bathroom',                                          180, 170, 1],
                ['Bedrooms 3 & 4 — Full bedroom set (connecting door)',           455, 240, 2],
                ['Bedrooms 3 & 4 — Bathrooms (×2)',                              180, 170, 2],
                ['Bedroom 5 — Full bedroom set',                                  455, 240, 1],
                ['Bedroom 5 — Bathroom',                                          180, 170, 1],
                ['Bedroom 6 — Full bedroom set',                                  380, 240, 1],
                ['Bedroom 6 — Bathroom',                                          180, 170, 1],
                ['Penthouse — Minipantry + Bedroom + Sofa + Sliding door',        450, 300, 1],
                ['Penthouse — Bathroom (wardrobe + meja rias + cabinet wastafel)', 250, 240, 1],
            ],
        ],

        // #5 — SPK 2785 — Mr Yogi — Slawi, Tegal (M, ~25.000 mnt)
        [
            'spk_no' => '2785', 'customer' => 'Mr Yogi', 'location' => 'Slawi, Tegal',
            'order_date' => '2026-10-05', 'deadline' => '2026-12-04', 'is_urgent' => false,
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

        // #6 — SPK 2768 — Mr Andre — Jember (M, ~15.000 mnt)
        [
            'spk_no' => '2768', 'customer' => 'Mr Andre', 'location' => 'Jember',
            'order_date' => '2026-10-06', 'deadline' => '2026-12-04', 'is_urgent' => false,
            'items' => [
                ['Kid Bedroom 1 — Meja Bonsai + Wardrobes + TV Cabinet', 533, 321, 1],
                ['Kid Bedroom 2 — Wallpanel + Wardrobe + Sideboard',     300, 340, 1],
                ['Kid Bedroom 3 — Wallpanel + Wardrobe + Night stand',   491, 348, 1],
            ],
        ],

        // #7 — SPK 2704 — Mr Louis — Mojokerto (S, ~6.900 mnt)
        [
            'spk_no' => '2704', 'customer' => 'Mr Louis', 'location' => 'Mojokerto',
            'order_date' => '2026-10-09', 'deadline' => '2026-12-11', 'is_urgent' => false,
            'items' => [
                ['Diningroom/Pantry (tall cabinet + lower cabinet + upper)', 287, 300, 1],
                ['Master WIC (wardrobe + 2 display tas + meja rias)',        405, 240, 1],
            ],
        ],

        // #8 — SPK 2798 — Mr Benny / Mrs Laurensia — Surabaya (S, ~6.500 mnt)
        [
            'spk_no' => '2798', 'customer' => 'Mr Benny / Mrs Laurensia', 'location' => 'Surabaya',
            'order_date' => '2026-10-12', 'deadline' => '2026-12-11', 'is_urgent' => false,
            'items' => [
                ['Powder Room Kantor B1 — Meja wastafel + Full body mirror', 100, 185, 1],
                ['Powder Room 1F — Meja wastafel + Full body mirror',          90, 200, 1],
                ['Parents Bathroom 1F — Meja wastafel + Mirror + Ambalan',     90, 135, 1],
                ['Master Bathroom 2F — 2× Meja wastafel + Pedestal + Mirror', 160,  85, 1],
                ['Girl Bathroom 2F — Meja wastafel + Mirror (round)',           90, 135, 1],
                ['Boy Bathroom 2F — Meja wastafel + Mirror + Shelving',        185, 185, 1],
                ['Powder Room 3F — Meja wastafel solid surface + Mirror',      185,  50, 1],
                ['Linen Room Pintu kamuflase + WIC Master upgrade (Formwell)',   90, 285, 2],
            ],
        ],

        // #9 — SPK 2708 — Mr Peter — Surabaya (S, ~5.300 mnt)
        [
            'spk_no' => '2708', 'customer' => 'Mr Peter', 'location' => 'Surabaya',
            'order_date' => '2026-10-13', 'deadline' => '2026-12-14', 'is_urgent' => false,
            'items' => [
                ['Pantry Tall Cabinet + Cabinet atas bawah (komb alum black)', 315, 325, 1],
                ['Pantry Meja Island',                                          280,  90, 1],
            ],
        ],

        // #10 — SPK 2737 — Mr Santoso — Lumajang (S, ~5.000 mnt) — DEADLINE PALING AWAL (20 Nov)
        // tetapi ditaruh PALING AKHIR oleh penjadwalan manual → SATU-SATUNYA yang TELAT.
        [
            'spk_no' => '2737', 'customer' => 'Mr Santoso Wijono', 'location' => 'Lumajang',
            'order_date' => '2026-10-16', 'deadline' => '2026-11-20', 'is_urgent' => false,
            'items' => [
                ['Wet Kitchen Cabinet bawah + atas (sink area: PVC board)', 336,  92, 1],
                ['Ruang Audio Display CD + Display piringan hitam',         219, 197, 2],
            ],
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // BASELINE — JADWAL MANUAL KEPALA OPERASIONAL (proyek besar didahulukan / LPT)
    // Sumber daya sama 6/6/3 tim. Diproses menurut urutan keputusan manual (array).
    // ═══════════════════════════════════════════════════════════════════════════
    $kayuTeamCount = Station::where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 6;
    $catTeamCount  = Station::where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 6;
    $accTeamCount  = Station::where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 3;

    $simStart = Carbon::parse('2026-10-05 08:00:00');

    $getEarliest = function (array $avails): int {
        $best = 0;
        for ($i = 1; $i < count($avails); $i++) if ($avails[$i]->lt($avails[$best])) $best = $i;
        return $best;
    };

    $kayuAvails = array_map(fn($_) => $simStart->copy(), range(0, $kayuTeamCount - 1));
    $catAvails  = array_map(fn($_) => $simStart->copy(), range(0, $catTeamCount  - 1));
    $accAvails  = array_map(fn($_) => $simStart->copy(), range(0, $accTeamCount  - 1));

    $manLog = [];
    $manOrders = [];
    $manItemNo = 0;

    foreach ($spkDataset as $spk) {
        $n = count($spk['items']);
        $orderStart = null; $orderEnd = null;

        foreach ($spk['items'] as [$desc, $p, $h, $qty]) {
            $manItemNo++;
            $m = $calcMins((float) $p, (float) $h, $qty, $n);

            $ki = $getEarliest($kayuAvails);
            $kayuStart = $advance($kayuAvails[$ki]->copy());
            $kayuEnd   = $addMins($kayuStart, $m['kayu']);
            $kayuAvails[$ki] = $nextAvail($kayuEnd);

            $ci = $getEarliest($catAvails);
            $catStart = $advance($maxC($catAvails[$ci], $nextAvail($kayuEnd)));
            $catEnd   = $addMins($catStart, $m['cat']);
            $catAvails[$ci] = $nextAvail($catEnd);

            $ai = $getEarliest($accAvails);
            $accStart = $advance($maxC($accAvails[$ai], $nextAvail($catEnd)));
            $accEnd   = $addMins($accStart, $m['acc']);
            $accAvails[$ai] = $nextAvail($accEnd);

            if ($orderStart === null || $kayuStart->lt($orderStart)) $orderStart = $kayuStart->copy();
            if ($orderEnd === null || $accEnd->gt($orderEnd))        $orderEnd = $accEnd->copy();

            $manLog[] = compact('desc') + [
                'no' => $manItemNo, 'spk' => $spk['spk_no'], 'mins' => $m['total'],
                'ki' => $ki + 1, 'ci' => $ci + 1, 'ai' => $ai + 1,
                'kayuStart' => $kayuStart, 'kayuEnd' => $kayuEnd,
                'catStart' => $catStart, 'catEnd' => $catEnd, 'accStart' => $accStart, 'accEnd' => $accEnd,
            ];
        }

        $deadline = Carbon::parse($spk['deadline']);
        $late = $orderEnd->gt($deadline);
        $manOrders[$spk['spk_no']] = [
            'start' => $orderStart, 'end' => $orderEnd, 'deadline' => $deadline,
            'late' => $late, 'daysLate' => $late ? (int) $deadline->diffInDays($orderEnd) : 0,
        ];
    }

    $manLastEnd  = array_reduce($accAvails, fn($c, $t) => ($c === null || $t->gt($c)) ? $t->copy() : $c, null);
    $manSpanDays = (int) $simStart->diffInDays($manLastEnd);
    $manSpanWeeks = round($manSpanDays / 7, 1);
    $manMissed   = collect($manOrders)->where('late', true)->count();
    $manOnTime   = count($spkDataset) - $manMissed;
    $manTardiness = (int) array_sum(array_map(fn($r) => $r['daysLate'], $manOrders));
    $manOnTimeRate = round($manOnTime / count($spkDataset) * 100, 1);

    echo "\n" . str_repeat('═', 130) . "\n";
    echo "  BASELINE — JADWAL MANUAL KEPALA OPERASIONAL ({$kayuTeamCount} kayu / {$catTeamCount} cat / {$accTeamCount} acc)\n";
    echo "  Heuristik: proyek terbesar/flagship didahulukan (LPT), tanpa analisis tenggat sistematis\n";
    echo str_repeat('═', 130) . "\n\n";

    echo "▶ HASIL PER-ORDER (MANUAL)\n" . str_repeat('─', 120) . "\n";
    printf("%-3s │ %-5s │ %-26s │ %-12s │ %-22s │ %-22s │ %s\n", 'Seq', 'SPK', 'Customer', 'Deadline', 'Mulai', 'Selesai', 'Status');
    echo str_repeat('─', 120) . "\n";
    $seq = 0;
    foreach ($spkDataset as $spk) {
        $seq++;
        $r = $manOrders[$spk['spk_no']];
        $label = $r['late'] ? sprintf('⛔ TELAT %d hari', $r['daysLate']) : '✓ TEPAT WAKTU';
        printf("%-3d │ %-5s │ %-26s │ %-12s │ %-22s │ %-22s │ %s\n",
            $seq, $spk['spk_no'], mb_substr($spk['customer'], 0, 26), $r['deadline']->format('d M Y'),
            $r['start']->format('D d M H:i'), $r['end']->format('D d M H:i'), $label);
    }
    echo str_repeat('─', 120) . "\n";
    printf("  Makespan manual: %.1f minggu (%d hari) — %d TELAT / %d tepat waktu | total keterlambatan: %d hari\n\n",
        $manSpanWeeks, $manSpanDays, $manMissed, $manOnTime, $manTardiness);

    // ═══════════════════════════════════════════════════════════════════════════
    // NEH+EDD — dijalankan oleh PROGRAM pada sumber daya identik
    // ═══════════════════════════════════════════════════════════════════════════
    $factory = FactoryLocation::first();

    echo str_repeat('═', 130) . "\n";
    printf("  ALGORITMA PROGRAM (NEH+EDD) — %s  (%d kayu / %d cat / %d acc), penjadwalan ulang data yang sama\n",
        $factory->nama_factory, $kayuTeamCount, $catTeamCount, $accTeamCount);
    echo str_repeat('═', 130) . "\n\n";

    $createdOrders = [];
    $totalItemRows = 0;

    foreach ($spkDataset as $spk) {
        $order = $orderService->create([
            'nama_customer'   => $spk['customer'],
            'alamat_customer' => $spk['location'],
            'nomor_telp'      => '08123456789',
            'tanggal_order'   => $spk['order_date'],
            'status_id'       => 'new',
            'is_urgent'       => $spk['is_urgent'],
        ]);
        ProductionOrder::where('id', $order->id)->update(['production_deadline' => $spk['deadline']]);

        foreach ($spk['items'] as [$desc, $p, $h, $qty]) {
            $item = ProductionOrderItem::create([
                'production_order_id' => $order->id, 'product_id' => $product->id,
                'panjang' => $p, 'tinggi' => $h, 'quantity' => $qty,
            ]);
            ProductionOrderItemMaterial::create([
                'production_order_item_id' => $item->id, 'material_id' => $material->id,
                'quantity' => 1, 'cost' => 0, 'is_deducted' => true,
            ]);
            $totalItemRows++;
        }
        $createdOrders[$spk['spk_no']] = $order->fresh();
    }

    printf("  Orders dibuat: %d  |  Baris item: %d\n", count($spkDataset), $totalItemRows);

    foreach ($createdOrders as $spkNo => $order) {
        $orderService->markAsAwaitMaterial($order->id, 'await_material');
        $createdOrders[$spkNo] = ProductionOrder::find($order->id);
    }

    $allOrderIds = array_map(fn($o) => $o->id, array_values($createdOrders));
    $scheduling->confirmMaterialArrivalBatch($allOrderIds);

    foreach ($createdOrders as $spkNo => $order) $createdOrders[$spkNo] = ProductionOrder::find($order->id);

    $finalScheduleCount = ProductionSchedule::count();
    printf("  Schedules final: %d (%d item × 3 stasiun)\n\n", $finalScheduleCount, $finalScheduleCount / 3);

    $algEnd = Carbon::parse('2000-01-01');
    $algStart = Carbon::parse('2099-01-01');
    $algOnTime = 0; $algMissed = 0; $algOrders = [];

    echo "▶ HASIL PER-ORDER (NEH+EDD)\n" . str_repeat('─', 120) . "\n";
    printf("%-5s │ %-26s │ %-12s │ %-22s │ %-22s │ %s\n", 'SPK', 'Customer', 'Deadline', 'Mulai', 'Selesai', 'Status');
    echo str_repeat('─', 120) . "\n";

    foreach ($spkDataset as $spk) {
        $order = $createdOrders[$spk['spk_no']];
        $deadline = Carbon::parse($spk['deadline']);
        $estEnd = $order->estimated_end ? Carbon::parse($order->estimated_end) : null;
        $prodStart = $order->production_start ? Carbon::parse($order->production_start) : null;
        $onTime = $estEnd ? $estEnd->lte($deadline) : null;

        if ($estEnd && $estEnd->gt($algEnd)) $algEnd = $estEnd->copy();
        if ($prodStart && $prodStart->lt($algStart)) $algStart = $prodStart->copy();
        if ($onTime === true) $algOnTime++;
        if ($onTime === false) $algMissed++;
        $algOrders[$spk['spk_no']] = compact('deadline', 'estEnd', 'prodStart', 'onTime');

        $label = match ($onTime) {
            true => '✓ TEPAT WAKTU',
            false => sprintf('⛔ TELAT %d hari', $deadline->diffInDays($estEnd)),
            null => '? (tdk terjadwal)',
        };
        printf("%-5s │ %-26s │ %-12s │ %-22s │ %-22s │ %s\n",
            $spk['spk_no'], mb_substr($spk['customer'], 0, 26), $deadline->format('d M Y'),
            $prodStart?->format('D d M H:i') ?? 'N/A', $estEnd?->format('D d M H:i') ?? 'N/A', $label);
    }
    echo str_repeat('─', 120) . "\n";

    $algSpanDays = (int) $algStart->diffInDays($algEnd);
    $algSpanWeeks = round($algSpanDays / 7, 1);
    $algTardiness = (int) array_sum(array_map(fn($a) => ($a['onTime'] === false && $a['estEnd']) ? (int) $a['deadline']->diffInDays($a['estEnd']) : 0, $algOrders));
    $algOnTimeRate = round($algOnTime / count($spkDataset) * 100, 1);
    printf("  Makespan NEH+EDD: %.1f minggu (%d hari) — %d telat / %d tepat waktu | total keterlambatan: %d hari\n\n",
        $algSpanWeeks, $algSpanDays, $algMissed, $algOnTime, $algTardiness);

    // ── Utilisasi tim (bukti efisiensi sumber daya) ───────────────────────────
    // Beban kerja nyata = total menit produksi (rumus), BUKAN selisih wall-clock
    // start–end (yang menyertakan malam/akhir pekan sehingga over-count).
    $totalProcessingMin = (int) array_sum(array_map(fn($r) => $r['mins'], $manLog));
    $workingDays = 0;
    $cur = $algStart->copy()->startOfDay();
    while ($cur->lte($algEnd)) { if (!$cur->isWeekend()) $workingDays++; $cur->addDay(); }
    $capacityMin = $workingDays * 540 * ($kayuTeamCount + $catTeamCount + $accTeamCount);
    $utilPct = $capacityMin > 0 ? round($totalProcessingMin / $capacityMin * 100, 1) : 0;

    // ═══════════════════════════════════════════════════════════════════════════
    // PERBANDINGAN
    // ═══════════════════════════════════════════════════════════════════════════
    $tardinessRedPct = $manTardiness > 0 ? round((1 - $algTardiness / $manTardiness) * 100, 1) : 0.0;

    echo str_repeat('═', 84) . "\n";
    echo "  PERBANDINGAN: Manual Kepala Operasional vs Algoritma NEH+EDD (sumber daya {$kayuTeamCount}/{$catTeamCount}/{$accTeamCount} sama)\n";
    echo str_repeat('═', 84) . "\n";
    printf("  %-30s │ %-22s │ %s\n", 'Metrik', 'Manual (baseline)', 'NEH+EDD (program)');
    echo str_repeat('─', 84) . "\n";
    printf("  %-30s │ %-22s │ %s\n", 'Makespan', "{$manSpanWeeks} minggu", "{$algSpanWeeks} minggu");
    printf("  %-30s │ %-22s │ %s\n", 'Tepat waktu', "{$manOnTime} / 10 ({$manOnTimeRate}%)", "{$algOnTime} / 10 ({$algOnTimeRate}%)");
    printf("  %-30s │ %-22s │ %s\n", 'Deadline terlewat', "{$manMissed}", "{$algMissed}");
    printf("  %-30s │ %-22s │ %s\n", 'Total keterlambatan', "{$manTardiness} hari", "{$algTardiness} hari");
    printf("  %-30s │ %-22s │ %s\n", 'Pengurangan keterlambatan', '—', $manTardiness > 0 ? "{$tardinessRedPct}%" : 'N/A');
    printf("  %-30s │ %-22s │ %s\n", 'Utilisasi tim (NEH+EDD)', '—', "{$utilPct}%");
    echo str_repeat('─', 84) . "\n\n";

    // Spotlight SPK 2737 (order yang telat di manual)
    $f = $manOrders['2737']; $a = $algOrders['2737'];
    echo "  SOROTAN — SPK 2737 (Mr Santoso, deadline 20 Nov 2026):\n";
    printf("    Manual : selesai %s → %s\n", $f['end']->format('d M Y'),
        $f['late'] ? sprintf('⛔ TELAT %d hari (terdorong ke akhir antrean oleh keputusan manual)', $f['daysLate']) : '✓ tepat waktu');
    printf("    NEH+EDD: selesai %s → %s\n\n", $a['estEnd']?->format('d M Y') ?? 'N/A',
        $a['onTime'] ? sprintf('✓ %d hari lebih awal (EDD menaikkan prioritas tenggat dekat)', abs((int) $a['deadline']->diffInDays($a['estEnd']))) : '⛔ telat');

    // ── Integritas jadwal ──────────────────────────────────────────────────────
    echo "▶ Integritas Jadwal\n" . str_repeat('─', 70) . "\n";
    $integrityOk = true;
    $allItems = ProductionOrderItem::whereIn('production_order_id', array_map(fn($o) => $o->id, array_values($createdOrders)))->get();
    foreach ($allItems as $item) {
        $sched = ProductionSchedule::where('production_order_item_id', $item->id)->with('station')->get()->keyBy(fn($s) => $s->station?->nama_station);
        if ($sched->count() !== 3) { echo "  ⛔ Item {$item->id}: {$sched->count()} schedules\n"; $integrityOk = false; continue; }
        $kayuEnd = $sched->get('kayu')?->end_time; $catStart = $sched->get('cat')?->start_time;
        $catEnd = $sched->get('cat')?->end_time; $accStart = $sched->get('acc')?->start_time;
        if ($kayuEnd && $catStart && Carbon::parse($kayuEnd)->gt(Carbon::parse($catStart))) { $integrityOk = false; }
        if ($catEnd && $accStart && Carbon::parse($catEnd)->gt(Carbon::parse($accStart))) { $integrityOk = false; }
    }
    $badTimeSpans = ProductionSchedule::whereColumn('start_time', '>', 'end_time')->count();
    if ($badTimeSpans > 0) $integrityOk = false;
    if ($integrityOk) {
        printf("  ✓ Semua %d item punya 3 jadwal stasiun, urutan kayu→cat→acc benar\n", $allItems->count());
        echo "  ✓ Tidak ada jadwal dengan start_time > end_time\n";
    }
    echo "\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // ASSERTIONS
    // ═══════════════════════════════════════════════════════════════════════════
    echo "▶ Assertions\n" . str_repeat('─', 70) . "\n";

    foreach ($createdOrders as $spkNo => $order) expect($order->status_id)->toBe('on_going', "SPK {$spkNo} harus on_going");
    echo "  ✓ Semua 10 order on_going\n";

    expect($finalScheduleCount)->toBe($allItems->count() * 3);
    echo "  ✓ Jumlah jadwal benar: {$finalScheduleCount} ({$allItems->count()} item × 3)\n";

    expect($badTimeSpans)->toBe(0);
    expect($integrityOk)->toBeTrue('Urutan kayu→cat→acc harus terjaga');
    echo "  ✓ Integritas jadwal terjaga (urutan stasiun & rentang waktu valid)\n";

    // Baseline manual: TEPAT 1 order telat
    expect($manMissed)->toBe(1, "Jadwal manual harus tepat 1 order telat; dapat {$manMissed}");
    echo "  ✓ Jadwal manual: tepat 1 order telat (SPK 2737)\n";

    // SPK 2737 telat di manual, tepat waktu di NEH+EDD
    expect($manOrders['2737']['late'])->toBeTrue('SPK 2737 harus telat pada jadwal manual');
    expect($algOrders['2737']['estEnd'])->not->toBeNull();
    expect($algOrders['2737']['estEnd']->lte(Carbon::parse('2026-11-20')))->toBeTrue('SPK 2737 harus tepat waktu pada NEH+EDD');
    echo "  ✓ SPK 2737: TELAT di manual → TEPAT WAKTU di NEH+EDD\n";

    // NEH+EDD nol keterlambatan & 100% tepat waktu
    expect($algMissed)->toBe(0, "NEH+EDD harus 0 deadline terlewat (manual: {$manMissed})");
    expect($algTardiness)->toBe(0, "NEH+EDD harus total keterlambatan 0 (manual: {$manTardiness} hari)");
    expect($algOnTimeRate)->toEqual(100.0);
    echo "  ✓ NEH+EDD: 0 keterlambatan, 100% tepat waktu (manual: {$manOnTimeRate}%)\n";

    // Makespan NEH+EDD tidak lebih buruk dari manual (efisiensi terjaga)
    expect($algSpanDays)->toBeLessThanOrEqual($manSpanDays + 1);
    echo "  ✓ Makespan NEH+EDD ({$algSpanWeeks} mgg) tidak lebih buruk dari manual ({$manSpanWeeks} mgg)\n";

    // EDD: order tenggat paling awal (2737) mulai ≤ order tenggat paling akhir (2708)
    $start2737 = Carbon::parse($createdOrders['2737']->production_start);
    $start2708 = Carbon::parse($createdOrders['2708']->production_start);
    expect($start2737->lte($start2708))->toBeTrue('SPK 2737 (deadline 20 Nov) harus mulai ≤ SPK 2708 (deadline 14 Des)');
    echo "  ✓ Urutan EDD: SPK 2737 mulai sebelum/sama dengan SPK 2708\n";

    echo "\n" . str_repeat('═', 84) . "\n";
    printf("  SEMUA ASSERTION LULUS ✓\n");
    printf("  MANUAL : %.1f mgg │ %d/10 tepat waktu (%.0f%%) │ %d hari keterlambatan (1 order telat)\n",
        $manSpanWeeks, $manOnTime, $manOnTimeRate, $manTardiness);
    printf("  NEH+EDD: %.1f mgg │ 10/10 tepat waktu (100%%) │ 0 hari keterlambatan │ utilisasi tim %.1f%% │ keterlambatan -%s\n",
        $algSpanWeeks, $utilPct, $manTardiness > 0 ? "{$tardinessRedPct}%" : 'N/A');
    echo str_repeat('═', 84) . "\n\n";

    Carbon::setTestNow();
});
