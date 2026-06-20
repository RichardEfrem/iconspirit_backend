<?php

/**
 * SPK Skenario Jan–Mar 2026: Baseline FCFS vs NEH+EDD
 *
 * Skenario fiktif namun realistis: 10 SPK nyata (2532–2798) dijadwalkan dalam
 * jendela Januari–Maret 2026 pada pabrik dengan 6 tim kayu / 6 tim cat / 3 tim acc.
 *
 * Urutan ARRIVAL (kedatangan order) sengaja disusun agar 4 order kecil ber-deadline
 * KETAT (2708, 2737, 2704, 2798) datang PALING AKHIR (#7–#10). FCFS yang memproses
 * murni urut kedatangan akan mengubur keempatnya di belakang ~60 item order besar
 * sehingga MELEWATI deadline. NEH+EDD menariknya ke depan via prioritas deadline
 * sehingga seluruh order SELESAI TEPAT WAKTU.
 *
 * Formula, sumber daya (6/6/3 tim), dan kalender kerja identik untuk kedua metode —
 * yang berbeda hanya STRATEGI URUTAN.
 *
 * Seluruh dimensi dalam cm (dikonversi dari mm pada dokumen SPK asli).
 * Anchor simulasi: 2026-01-05 08:00.
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

test('SPK Jan-Mar 2026: NEH+EDD vs computed FCFS baseline', function () {

    $this->seed();
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

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
        if ($t->isWeekend()) {
            return $t->next(Carbon::MONDAY)->setTime(8, 0, 0);
        }
        if ($t->format('H:i:s') < '08:00:00') {
            return $t->setTime(8, 0, 0);
        }
        if ($t->format('H:i:s') >= '17:00:00') {
            $t->addDay()->setTime(8, 0, 0);
            if ($t->isWeekend()) {
                $t->next(Carbon::MONDAY)->setTime(8, 0, 0);
            }
        }
        return $t;
    };

    $addMins = function (Carbon $start, float $mins) use ($advance): Carbon {
        $c = $advance($start->copy());
        $rem = $mins;
        while ($rem > 0) {
            $eod = $c->copy()->setTime(17, 0, 0);
            $avail = $c->diffInMinutes($eod);
            if ($rem <= $avail) {
                $c->addMinutes((int) $rem);
                $rem = 0;
            } else {
                $rem -= $avail;
                $c->addDay()->setTime(8, 0, 0);
                $c = $advance($c);
            }
        }
        return $c;
    };

    $nextAvail = function (Carbon $end) use ($advance): Carbon {
        if ($end->format('H:i:s') >= '15:00:00') {
            $next = $end->copy()->addDay()->setTime(8, 0, 0);
            return $advance($next);
        }
        return $end->copy();
    };

    $maxC = fn(Carbon $a, Carbon $b): Carbon => $a->gt($b) ? $a->copy() : $b->copy();

    // Duration formula (identical to SchedulingService::calculateJobMinutes)
    $calcMins = function (float $p, float $h, int $qty, int $n): array {
        $base  = 1440.0 / max(1, $n);
        $area  = ($p * $h) / 10000.0;
        $var   = $area * $qty * 300.0;
        $total = $base + $var;
        return [
            'kayu'  => $total * 0.4,
            'cat'   => $total * 0.4,
            'acc'   => $total * 0.2,
            'total' => $total,
        ];
    };

    $product      = Product::first();
    $material     = Material::first();
    $orderService = app(ProductionOrderService::class);
    $scheduling   = app(SchedulingService::class);

    // ── SPK dataset (ARRAY ORDER = urutan kedatangan / arrival order untuk FCFS) ──
    $spkDataset = [

        // #1 — SPK 2532 — Mrs Lidya / Mr Donny — Kupang NTT (XL, deadline longgar)
        [
            'spk_no'    => '2532',
            'customer'  => 'Mrs Lidya / Mr Donny',
            'location'  => 'Kupang NTT',
            'order_date' => '2026-01-02',
            'deadline'  => '2026-02-27',
            'is_urgent' => false,
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

        // #2 — SPK 2546 — Mr Cahyadi — Pakuwon Indah Surabaya (XL, deadline longgar)
        [
            'spk_no'    => '2546',
            'customer'  => 'Mr Cahyadi',
            'location'  => 'Surabaya',
            'order_date' => '2026-01-02',
            'deadline'  => '2026-03-06',
            'is_urgent' => false,
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

        // #3 — SPK 2685 — Mrs Helena — Graha Familly Surabaya (L)
        [
            'spk_no'    => '2685',
            'customer'  => 'Mrs Helena',
            'location'  => 'Surabaya',
            'order_date' => '2026-01-05',
            'deadline'  => '2026-02-20',
            'is_urgent' => false,
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

        // #4 — SPK 2770 — Mr Andrew — Villa Bali (L, deadline longgar)
        [
            'spk_no'    => '2770',
            'customer'  => 'Mr Andrew',
            'location'  => 'Villa Bali',
            'order_date' => '2026-01-05',
            'deadline'  => '2026-03-13',
            'is_urgent' => false,
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

        // #5 — SPK 2768 — Mr Andre — Jember (M)
        [
            'spk_no'    => '2768',
            'customer'  => 'Mr Andre',
            'location'  => 'Jember',
            'order_date' => '2026-01-09',
            'deadline'  => '2026-02-27',
            'is_urgent' => false,
            'items' => [
                ['Kid Bedroom 1 — Meja Bonsai + Wardrobes + TV Cabinet', 533, 321, 1],
                ['Kid Bedroom 2 — Wallpanel + Wardrobe + Sideboard',     300, 340, 1],
                ['Kid Bedroom 3 — Wallpanel + Wardrobe + Night stand',   491, 348, 1],
            ],
        ],

        // #6 — SPK 2785 — Mr Yogi — Slawi, Tegal (M)
        [
            'spk_no'    => '2785',
            'customer'  => 'Mr Yogi',
            'location'  => 'Slawi, Tegal',
            'order_date' => '2026-01-12',
            'deadline'  => '2026-03-04',
            'is_urgent' => false,
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

        // #7 — SPK 2708 — Mr Peter — Royal Residence Surabaya (S, DEADLINE KETAT, datang akhir)
        [
            'spk_no'    => '2708',
            'customer'  => 'Mr Peter',
            'location'  => 'Surabaya',
            'order_date' => '2026-01-16',
            'deadline'  => '2026-01-30',
            'is_urgent' => false,
            'items' => [
                ['Pantry Tall Cabinet + Cabinet atas bawah (komb alum black)', 315, 325, 1],
                ['Pantry Meja Island',                                          280,  90, 1],
            ],
        ],

        // #8 — SPK 2737 — Mr Santoso Wijono — Lumajang (S, DEADLINE KETAT, datang akhir)
        [
            'spk_no'    => '2737',
            'customer'  => 'Mr Santoso Wijono',
            'location'  => 'Lumajang',
            'order_date' => '2026-01-19',
            'deadline'  => '2026-02-02',
            'is_urgent' => false,
            'items' => [
                ['Wet Kitchen Cabinet bawah + atas (sink area: PVC board)', 336,  92, 1],
                ['Ruang Audio Display CD + Display piringan hitam',         219, 197, 2],
            ],
        ],

        // #9 — SPK 2704 — Mr Louis — Mojokerto (S, DEADLINE KETAT, datang akhir)
        [
            'spk_no'    => '2704',
            'customer'  => 'Mr Louis',
            'location'  => 'Mojokerto',
            'order_date' => '2026-01-23',
            'deadline'  => '2026-02-06',
            'is_urgent' => false,
            'items' => [
                ['Diningroom/Pantry (tall cabinet + lower cabinet + upper)', 287, 300, 1],
                ['Master WIC (wardrobe + 2 display tas + meja rias)',        405, 240, 1],
            ],
        ],

        // #10 — SPK 2798 — Mr Benny / Mrs Laurensia — Surabaya (S, DEADLINE KETAT, datang paling akhir)
        [
            'spk_no'    => '2798',
            'customer'  => 'Mr Benny / Mrs Laurensia',
            'location'  => 'Surabaya',
            'order_date' => '2026-01-26',
            'deadline'  => '2026-02-09',
            'is_urgent' => false,
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
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // FCFS BASELINE — sama 6/6/3 tim, item diproses urut kedatangan SPK saja.
    // ═══════════════════════════════════════════════════════════════════════════

    $kayuTeamCount = Station::where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 6;
    $catTeamCount  = Station::where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 6;
    $accTeamCount  = Station::where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 3;

    $simStart = Carbon::parse('2026-01-05 08:00:00');

    $getEarliest = function (array $avails): int {
        $best = 0;
        for ($i = 1; $i < count($avails); $i++) {
            if ($avails[$i]->lt($avails[$best])) $best = $i;
        }
        return $best;
    };

    $kayuAvails = array_map(fn($_) => $simStart->copy(), range(0, $kayuTeamCount - 1));
    $catAvails  = array_map(fn($_) => $simStart->copy(), range(0, $catTeamCount  - 1));
    $accAvails  = array_map(fn($_) => $simStart->copy(), range(0, $accTeamCount  - 1));

    $fifoLog    = [];
    $fifoOrders = [];
    $fifoItemNo = 0;

    foreach ($spkDataset as $spk) {
        $n = count($spk['items']);
        $orderStart = null;
        $orderEnd   = null;

        foreach ($spk['items'] as [$desc, $p, $h, $qty]) {
            $fifoItemNo++;
            $m = $calcMins((float) $p, (float) $h, $qty, $n);

            $ki        = $getEarliest($kayuAvails);
            $kayuStart = $advance($kayuAvails[$ki]->copy());
            $kayuEnd   = $addMins($kayuStart, $m['kayu']);
            $kayuAvails[$ki] = $nextAvail($kayuEnd);

            $ci       = $getEarliest($catAvails);
            $catStart = $advance($maxC($catAvails[$ci], $nextAvail($kayuEnd)));
            $catEnd   = $addMins($catStart, $m['cat']);
            $catAvails[$ci] = $nextAvail($catEnd);

            $ai       = $getEarliest($accAvails);
            $accStart = $advance($maxC($accAvails[$ai], $nextAvail($catEnd)));
            $accEnd   = $addMins($accStart, $m['acc']);
            $accAvails[$ai] = $nextAvail($accEnd);

            if ($orderStart === null || $kayuStart->lt($orderStart)) $orderStart = $kayuStart->copy();
            if ($orderEnd   === null || $accEnd->gt($orderEnd))      $orderEnd   = $accEnd->copy();

            $fifoLog[] = [
                'no' => $fifoItemNo, 'spk' => $spk['spk_no'], 'desc' => $desc, 'mins' => $m['total'],
                'ki' => $ki + 1, 'ci' => $ci + 1, 'ai' => $ai + 1,
                'kayuStart' => $kayuStart, 'kayuEnd' => $kayuEnd,
                'catStart' => $catStart, 'catEnd' => $catEnd,
                'accStart' => $accStart, 'accEnd' => $accEnd,
            ];
        }

        $deadline = Carbon::parse($spk['deadline']);
        $late     = $orderEnd->gt($deadline);
        $fifoOrders[$spk['spk_no']] = [
            'start' => $orderStart, 'end' => $orderEnd, 'deadline' => $deadline,
            'late' => $late, 'daysLate' => $late ? (int) $deadline->diffInDays($orderEnd) : 0,
        ];
    }

    $fifoLastEnd = array_reduce($accAvails, fn($c, $t) => ($c === null || $t->gt($c)) ? $t->copy() : $c, null);
    $fifoSpanDays  = (int) $simStart->diffInDays($fifoLastEnd);
    $fifoSpanWeeks = round($fifoSpanDays / 7, 1);
    $fifoMissed    = collect($fifoOrders)->where('late', true)->count();
    $fifoOnTime    = count($spkDataset) - $fifoMissed;
    $fifoTardiness = (int) array_sum(array_map(fn($r) => $r['daysLate'], $fifoOrders));
    $fifoOnTimeRate = round($fifoOnTime / count($spkDataset) * 100, 1);

    echo "\n" . str_repeat('═', 190) . "\n";
    echo "  FCFS BASELINE — {$kayuTeamCount} kayu / {$catTeamCount} cat / {$accTeamCount} acc teams, urut kedatangan, tanpa kesadaran deadline\n";
    echo "  Formula: base_production_minutes/item_count + area×qty×minutes_per_m2, kayu 40% / cat 40% / acc 20%\n";
    echo str_repeat('═', 190) . "\n\n";

    $colW = 20;
    printf("%-4s │ %-5s │ %-34s │ %7s │ %2s │ %-{$colW}s │ %-{$colW}s │ %2s │ %-{$colW}s │ %-{$colW}s │ %2s │ %-{$colW}s │ %-{$colW}s\n",
        '#', 'SPK', 'Description', 'Min', 'KT', 'Kayu Start', 'Kayu End', 'CT', 'Cat Start', 'Cat End', 'AT', 'Acc Start', 'Acc End');
    echo str_repeat('─', 190) . "\n";

    $prevSpk = null;
    foreach ($fifoLog as $row) {
        if ($prevSpk !== null && $row['spk'] !== $prevSpk) echo str_repeat('·', 170) . "\n";
        printf("%-4d │ %-5s │ %-34s │ %7.0f │ K%-1d │ %-{$colW}s │ %-{$colW}s │ C%-1d │ %-{$colW}s │ %-{$colW}s │ A%-1d │ %-{$colW}s │ %-{$colW}s\n",
            $row['no'], $row['spk'], mb_substr($row['desc'], 0, 34), $row['mins'], $row['ki'],
            $row['kayuStart']->format('d M Y H:i'), $row['kayuEnd']->format('d M Y H:i'), $row['ci'],
            $row['catStart']->format('d M Y H:i'), $row['catEnd']->format('d M Y H:i'), $row['ai'],
            $row['accStart']->format('d M Y H:i'), $row['accEnd']->format('d M Y H:i'));
        $prevSpk = $row['spk'];
    }
    echo str_repeat('─', 190) . "\n\n";

    echo "▶ HASIL PER-ORDER FCFS\n" . str_repeat('─', 120) . "\n";
    printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n", 'SPK', 'Customer', 'Deadline', 'FCFS Start', 'FCFS End', 'Result');
    echo str_repeat('─', 120) . "\n";
    foreach ($spkDataset as $spk) {
        $r = $fifoOrders[$spk['spk_no']];
        $label = $r['late'] ? sprintf('⛔ LATE by %d days', $r['daysLate']) : '✓ ON TIME';
        printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n",
            $spk['spk_no'], mb_substr($spk['customer'], 0, 28), $r['deadline']->format('d M Y'),
            $r['start']->format('D d M Y H:i'), $r['end']->format('D d M Y H:i'), $label);
    }
    echo str_repeat('─', 120) . "\n";
    printf("  FCFS makespan: %.1f minggu (%d hari) — %d telat / %d tepat waktu\n\n",
        $fifoSpanWeeks, $fifoSpanDays, $fifoMissed, $fifoOnTime);

    // ═══════════════════════════════════════════════════════════════════════════
    // NEH+EDD RUN (DB-backed, 6 kayu / 6 cat / 3 acc teams)
    // ═══════════════════════════════════════════════════════════════════════════
    $factory  = FactoryLocation::first();

    echo str_repeat('═', 170) . "\n";
    printf("  NEH+EDD — %s  (%d kayu / %d cat / %d acc teams), dioptimasi deadline\n",
        $factory->nama_factory, $kayuTeamCount, $catTeamCount, $accTeamCount);
    echo str_repeat('═', 170) . "\n\n";

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
                'production_order_id' => $order->id,
                'product_id'          => $product->id,
                'panjang'             => $p,
                'tinggi'              => $h,
                'quantity'            => $qty,
            ]);
            // Each item needs ≥1 material before it can be processed (markAsAwaitMaterial guard)
            ProductionOrderItemMaterial::create([
                'production_order_item_id' => $item->id,
                'material_id'              => $material->id,
                'quantity'                 => 1,
                'cost'                     => 0,
                'is_deducted'              => true,
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

    printf("  Pending schedules setelah await individual: %d\n", ProductionSchedule::count());

    $allOrderIds = array_map(fn($o) => $o->id, array_values($createdOrders));
    $scheduling->confirmMaterialArrivalBatch($allOrderIds);

    foreach ($createdOrders as $spkNo => $order) {
        $createdOrders[$spkNo] = ProductionOrder::find($order->id);
    }

    $finalScheduleCount = ProductionSchedule::count();
    printf("  Schedules final setelah re-optimisasi: %d (%d items × 3 stations)\n\n",
        $finalScheduleCount, $finalScheduleCount / 3);

    $algEnd   = Carbon::parse('2000-01-01');
    $algStart = Carbon::parse('2099-01-01');
    $algOnTime = 0;
    $algMissed = 0;
    $algOrders = [];

    echo "▶ HASIL PER-ORDER NEH+EDD\n" . str_repeat('─', 130) . "\n";
    printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n", 'SPK', 'Customer', 'Deadline', 'NEH Start', 'NEH End', 'Result');
    echo str_repeat('─', 130) . "\n";

    foreach ($spkDataset as $spk) {
        $order    = $createdOrders[$spk['spk_no']];
        $deadline = Carbon::parse($spk['deadline']);
        $estEnd   = $order->estimated_end   ? Carbon::parse($order->estimated_end)   : null;
        $prodStart = $order->production_start ? Carbon::parse($order->production_start) : null;
        $onTime   = $estEnd ? $estEnd->lte($deadline) : null;

        if ($estEnd && $estEnd->gt($algEnd))         $algEnd   = $estEnd->copy();
        if ($prodStart && $prodStart->lt($algStart))  $algStart = $prodStart->copy();
        if ($onTime === true)  $algOnTime++;
        if ($onTime === false) $algMissed++;

        $algOrders[$spk['spk_no']] = compact('deadline', 'estEnd', 'prodStart', 'onTime');

        $label = match ($onTime) {
            true  => '✓ ON TIME',
            false => sprintf('⛔ LATE by %d days', $deadline->diffInDays($estEnd)),
            null  => '? (no schedule)',
        };

        printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n",
            $spk['spk_no'], mb_substr($spk['customer'], 0, 28), $deadline->format('d M Y'),
            $prodStart?->format('D d M Y H:i') ?? 'N/A', $estEnd?->format('D d M Y H:i') ?? 'N/A', $label);
    }
    echo str_repeat('─', 130) . "\n";

    $algSpanDays  = (int) $algStart->diffInDays($algEnd);
    $algSpanWeeks = round($algSpanDays / 7, 1);
    printf("  NEH+EDD makespan: %.1f minggu (%d hari) — %d telat / %d tepat waktu\n\n",
        $algSpanWeeks, $algSpanDays, $algMissed, $algOnTime);

    $algTardiness = (int) array_sum(array_map(function ($a) {
        return ($a['onTime'] === false && $a['estEnd'])
            ? (int) $a['deadline']->diffInDays($a['estEnd']) : 0;
    }, $algOrders));
    $algOnTimeRate   = round($algOnTime / count($spkDataset) * 100, 1);
    $tardinessRedPct = $fifoTardiness > 0 ? round((1 - $algTardiness / $fifoTardiness) * 100, 1) : 0.0;

    echo "▶ JADWAL STASIUN LENGKAP NEH+EDD (urut start_time)\n" . str_repeat('─', 155) . "\n";
    printf("%-4s │ %-5s │ %-34s │ %-8s │ %-10s │ %-26s │ %-26s\n", '#', 'SPK', 'Description', 'Station', 'Team', 'Start', 'End');
    echo str_repeat('─', 155) . "\n";

    $orderToSpk = [];
    foreach ($createdOrders as $spkNo => $order) $orderToSpk[$order->id] = $spkNo;

    $schedules = ProductionSchedule::with(['station', 'team', 'orderItem.productionOrder'])->orderBy('start_time')->get();

    $prevSpkAlg = null;
    $algRowNo   = 0;
    foreach ($schedules as $s) {
        $algRowNo++;
        $spkNo = $orderToSpk[$s->orderItem->production_order_id] ?? '?';
        if ($prevSpkAlg !== null && $spkNo !== $prevSpkAlg) echo str_repeat('·', 155) . "\n";
        printf("%-4d │ %-5s │ %-34s │ %-8s │ %-10s │ %-26s │ %-26s\n",
            $algRowNo, $spkNo, mb_substr($s->orderItem->product?->nama_product ?? '', 0, 34),
            strtoupper($s->station?->nama_station ?? '?'), $s->team?->kode_team ?? '?',
            $s->start_time?->format('D d M Y H:i') ?? 'N/A', $s->end_time?->format('D d M Y H:i') ?? 'N/A');
        $prevSpkAlg = $spkNo;
    }
    echo str_repeat('─', 155) . "\n\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // PERBANDINGAN SIDE-BY-SIDE
    // ═══════════════════════════════════════════════════════════════════════════
    $improvement = $fifoSpanDays > 0 ? round((1 - $algSpanDays / $fifoSpanDays) * 100, 1) : 0;

    echo str_repeat('═', 80) . "\n";
    echo "  PERBANDINGAN: FCFS ({$kayuTeamCount}/{$catTeamCount}/{$accTeamCount} tim) vs NEH+EDD ({$kayuTeamCount}/{$catTeamCount}/{$accTeamCount} tim)\n";
    echo str_repeat('═', 80) . "\n";
    printf("  %-30s │ %-20s │ %s\n", 'Metrik', 'FCFS Baseline', 'NEH+EDD');
    echo str_repeat('─', 80) . "\n";
    printf("  %-30s │ %-20s │ %s\n", 'Total makespan', "{$fifoSpanWeeks} minggu", "{$algSpanWeeks} minggu");
    printf("  %-30s │ %-20s │ %s\n", 'Tanggal mulai', $simStart->format('d M Y'), $algStart->format('d M Y'));
    printf("  %-30s │ %-20s │ %s\n", 'Tanggal selesai', $fifoLastEnd->format('d M Y'), $algEnd->format('d M Y'));
    printf("  %-30s │ %-20s │ %s\n", 'Deadline terlewat', "{$fifoMissed} / " . count($spkDataset), "{$algMissed} / " . count($spkDataset));
    printf("  %-30s │ %-20s │ %s\n", 'Deadline terpenuhi', "{$fifoOnTime} / " . count($spkDataset), "{$algOnTime} / " . count($spkDataset));
    printf("  %-30s │ %-20s │ %s\n", 'Perbaikan makespan', '—', "{$improvement}% lebih cepat");
    printf("  %-30s │ %-20s │ %s\n", 'On-time delivery rate', "{$fifoOnTimeRate}%", "{$algOnTimeRate}%");
    printf("  %-30s │ %-20s │ %s\n", 'Total tardiness (hari)', "{$fifoTardiness} hari", "{$algTardiness} hari");
    printf("  %-30s │ %-20s │ %s\n", 'Pengurangan tardiness', '—', $fifoTardiness > 0 ? "{$tardinessRedPct}% lebih sedikit" : 'N/A');
    echo str_repeat('─', 80) . "\n\n";

    echo "  Perbandingan per-order (FCFS end vs NEH+EDD end):\n" . str_repeat('─', 100) . "\n";
    printf("  %-5s │ %-14s │ %-26s │ %-10s │ %-26s │ %-10s │ %s\n", 'SPK', 'Deadline', 'FCFS End', 'FCFS', 'NEH End', 'NEH', 'Winner');
    echo str_repeat('─', 100) . "\n";
    foreach ($spkDataset as $spk) {
        $f = $fifoOrders[$spk['spk_no']];
        $a = $algOrders[$spk['spk_no']];
        $fifoLabel = $f['late'] ? "⛔ +{$f['daysLate']}d" : '✓';
        $algLabel  = $a['onTime'] ? '✓' : sprintf('⛔ +%dd', Carbon::parse($spk['deadline'])->diffInDays($a['estEnd']));
        if ($f['late'] && $a['onTime'])        $winner = '◀ NEH wins';
        elseif (!$f['late'] && !$a['onTime'])  $winner = '⚠ both late';
        elseif (!$f['late'] && $a['onTime'])   $winner = '≈ both ok';
        else                                   $winner = '— tie';
        printf("  %-5s │ %-14s │ %-26s │ %-10s │ %-26s │ %-10s │ %s\n",
            $spk['spk_no'], Carbon::parse($spk['deadline'])->format('d M Y'),
            $f['end']->format('D d M Y H:i'), $fifoLabel,
            $a['estEnd']?->format('D d M Y H:i') ?? 'N/A', $algLabel, $winner);
    }
    echo str_repeat('─', 100) . "\n\n";

    // Spotlight SPK 2704 (deadline ketat 6 Feb 2026)
    $spk2704F = $fifoOrders['2704'];
    $spk2704A = $algOrders['2704'];
    $dead2704 = Carbon::parse('2026-02-06');
    echo "  SPK 2704 (Mr Louis — deadline ketat 06 Feb 2026):\n";
    printf("    FCFS: mulai %s, selesai %s → %s\n",
        $spk2704F['start']->format('d M Y'), $spk2704F['end']->format('d M Y'),
        $spk2704F['late'] ? sprintf('⛔ TELAT %d hari (terblok di belakang order besar)', $spk2704F['daysLate']) : '✓ tepat waktu');
    printf("    NEH:  mulai %s, selesai %s → %s\n\n",
        $spk2704A['prodStart']?->format('d M Y') ?? 'N/A', $spk2704A['estEnd']?->format('d M Y') ?? 'N/A',
        $spk2704A['onTime'] ? sprintf('✓ %d hari lebih awal (EDD menaikkan prioritas)', abs((int) $dead2704->diffInDays($spk2704A['estEnd']))) : '⛔ telat');

    // ── Schedule integrity ────────────────────────────────────────────────────
    echo "▶ Integritas Jadwal\n" . str_repeat('─', 70) . "\n";
    $integrityOk = true;
    $allItems = ProductionOrderItem::whereIn('production_order_id', array_map(fn($o) => $o->id, array_values($createdOrders)))->get();

    foreach ($allItems as $item) {
        $sched = ProductionSchedule::where('production_order_item_id', $item->id)->with('station')->get()->keyBy(fn($s) => $s->station?->nama_station);
        if ($sched->count() !== 3) {
            echo "  ⛔ Item {$item->id} punya {$sched->count()} schedules (harusnya 3)\n";
            $integrityOk = false;
            continue;
        }
        $kayuEnd  = $sched->get('kayu')?->end_time;
        $catStart = $sched->get('cat')?->start_time;
        $catEnd   = $sched->get('cat')?->end_time;
        $accStart = $sched->get('acc')?->start_time;
        if ($kayuEnd && $catStart && Carbon::parse($kayuEnd)->gt(Carbon::parse($catStart))) { echo "  ⛔ Item {$item->id}: kayu selesai SETELAH cat mulai\n"; $integrityOk = false; }
        if ($catEnd && $accStart && Carbon::parse($catEnd)->gt(Carbon::parse($accStart)))   { echo "  ⛔ Item {$item->id}: cat selesai SETELAH acc mulai\n"; $integrityOk = false; }
    }
    $badTimeSpans = ProductionSchedule::whereColumn('start_time', '>', 'end_time')->count();
    if ($badTimeSpans > 0) { echo "  ⛔ {$badTimeSpans} schedules punya start_time > end_time\n"; $integrityOk = false; }
    if ($integrityOk) {
        printf("  ✓ Semua %d item punya 3 schedules, urutan kayu→cat→acc benar\n", $allItems->count());
        echo "  ✓ Tidak ada schedule dengan start_time > end_time\n";
    }
    echo "\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // ASSERTIONS
    // ═══════════════════════════════════════════════════════════════════════════
    echo "▶ Assertions\n" . str_repeat('─', 70) . "\n";

    foreach ($createdOrders as $spkNo => $order) {
        expect($order->status_id)->toBe('on_going', "SPK {$spkNo} harus on_going");
    }
    echo "  ✓ Semua 10 order on_going\n";

    $expectedSchedules = $allItems->count() * 3;
    expect($finalScheduleCount)->toBe($expectedSchedules);
    echo "  ✓ Jumlah schedule benar: {$finalScheduleCount} ({$allItems->count()} item × 3)\n";

    expect($badTimeSpans)->toBe(0, 'Tidak boleh ada schedule start > end');
    echo "  ✓ Tidak ada start_time > end_time\n";

    expect($integrityOk)->toBeTrue('Urutan kayu→cat→acc harus terjaga tiap item');
    echo "  ✓ Urutan kayu→cat→acc utuh untuk semua item\n";

    // SPK 2704: NEH+EDD harus memenuhi deadline ketat (6 Feb 2026)
    expect($spk2704A['estEnd'])->not->toBeNull();
    expect($spk2704A['estEnd']->lte($dead2704))->toBeTrue(
        "SPK 2704 harus selesai sebelum 6 Feb 2026. Dapat: {$spk2704A['estEnd']?->toDateTimeString()}. " .
        "FCFS: " . ($spk2704F['late'] ? "TELAT {$spk2704F['daysLate']} hari" : "tepat waktu") . ".");
    echo "  ✓ SPK 2704 deadline dipenuhi NEH+EDD (FCFS " . ($spk2704F['late'] ? "telat {$spk2704F['daysLate']} hari" : "juga tepat") . ")\n";

    expect($algSpanDays)->toBeLessThanOrEqual($fifoSpanDays,
        "NEH+EDD ({$algSpanWeeks} mgg) harus ≤ FCFS ({$fifoSpanWeeks} mgg) dgn 6/6/3 tim sama");
    echo "  ✓ NEH+EDD makespan ({$algSpanWeeks} mgg) ≤ FCFS makespan ({$fifoSpanWeeks} mgg)\n";

    expect($algMissed)->toBe(0, "NEH+EDD harus 0 deadline terlewat; FCFS terlewat {$fifoMissed}");
    echo "  ✓ NEH+EDD 0 deadline terlewat (FCFS terlewat {$fifoMissed})\n";

    $start2532 = Carbon::parse($createdOrders['2532']->production_start);
    $start2770 = Carbon::parse($createdOrders['2770']->production_start);
    expect($start2532->lte($start2770))->toBeTrue('SPK 2532 (deadline 27 Feb) harus mulai ≤ SPK 2770 (deadline 13 Mar)');
    echo "  ✓ SPK 2532 mulai sebelum/sama dengan SPK 2770 (urutan EDD)\n";

    expect($algTardiness)->toBe(0, "NEH+EDD harus total tardiness nol (FCFS akumulasi {$fifoTardiness} hari)");
    echo "  ✓ NEH+EDD total tardiness = 0 hari (FCFS: {$fifoTardiness} hari — {$tardinessRedPct}% reduksi)\n";

    expect($algOnTimeRate)->toEqual(100.0, "NEH+EDD harus 100% on-time (FCFS: {$fifoOnTimeRate}%)");
    echo "  ✓ NEH+EDD on-time rate = {$algOnTimeRate}% (FCFS: {$fifoOnTimeRate}%)\n";

    echo "\n" . str_repeat('═', 80) . "\n";
    printf("  SEMUA ASSERTION LULUS ✓\n");
    printf("  FCFS:    %.1f mgg │ %d/%d on-time (%4.1f%%) │ %2d hari total tardiness\n",
        $fifoSpanWeeks, $fifoOnTime, count($spkDataset), $fifoOnTimeRate, $fifoTardiness);
    printf("  NEH+EDD: %.1f mgg │ %d/%d on-time (%4.1f%%) │  0 hari tardiness │ %.1f%% makespan lebih cepat │ %s tardiness hilang\n",
        $algSpanWeeks, $algOnTime, count($spkDataset), $algOnTimeRate, $improvement,
        $fifoTardiness > 0 ? "{$tardinessRedPct}%" : 'N/A');
    echo str_repeat('═', 80) . "\n\n";

    Carbon::setTestNow();
});
