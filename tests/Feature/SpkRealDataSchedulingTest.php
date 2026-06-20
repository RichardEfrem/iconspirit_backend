<?php

/**
 * SPK Real-Data: FIFO Baseline (computed) vs NEH+EDD
 *
 * Both FIFO and NEH+EDD use the same 6 kayu / 6 cat / 3 acc team resources.
 * FIFO processes items in SPK arrival order with no deadline-aware sorting and no
 * NEH insertion optimisation. NEH+EDD uses a four-way partition (urgent →
 * on_going → critical → normal NEH) to minimise makespan and protect deadlines.
 * Same formula, same resources — only the sequencing strategy differs.
 *
 * Output shows the full FIFO insertion schedule (item by item), the NEH+EDD
 * schedule (ordered by the algorithm), and a side-by-side comparison.
 *
 * All dimensions are in cm (converted from mm in the original SPK documents).
 * Simulation anchor: 2024-08-19 08:00.
 */

use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\Station;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SPK 2532-2798: NEH+EDD vs computed FIFO baseline', function () {

    $this->seed();
    Carbon::setTestNow(Carbon::parse('2024-08-19 08:00:00'));

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
    // advanceToWorkingHours: same logic verbatim
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

    // addWorkingMinutes: same logic verbatim
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

    // getNextAvailability: same logic verbatim (pushes to next morning if ≥15:00)
    $nextAvail = function (Carbon $end) use ($advance): Carbon {
        if ($end->format('H:i:s') >= '15:00:00') {
            $next = $end->copy()->addDay()->setTime(8, 0, 0);
            return $advance($next);
        }
        return $end->copy();
    };

    // maxCarbon: return the later of two Carbon instances
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
    $orderService = app(ProductionOrderService::class);
    $scheduling   = app(SchedulingService::class);

    // ── SPK dataset ───────────────────────────────────────────────────────────
    $spkDataset = [

        // SPK 2532 — Mrs Lidya / Mr Donny — Kupang NTT
        // Very large 2-floor project (11 rooms). Ordered Jun 2024; ~5.5-month deadline.
        [
            'spk_no'    => '2532',
            'customer'  => 'Mrs Lidya / Mr Donny',
            'location'  => 'Kupang NTT',
            'order_date' => '2024-06-10',
            'deadline'  => '2024-11-30',
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

        // SPK 2546 — Mr Cahyadi — Pakuwon Indah Surabaya
        // Very large 4-floor + basement project. Ordered Jul 2024; ~4.5-month deadline.
        [
            'spk_no'    => '2546',
            'customer'  => 'Mr Cahyadi',
            'location'  => 'Surabaya',
            'order_date' => '2024-07-08',
            'deadline'  => '2024-11-30',
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

        // SPK 2685 — Mrs Helena — Graha Familly Surabaya
        // Medium 2-floor project. Ordered Jul 2024; ~3.5-month deadline.
        [
            'spk_no'    => '2685',
            'customer'  => 'Mrs Helena',
            'location'  => 'Surabaya',
            'order_date' => '2024-07-22',
            'deadline'  => '2024-11-15',
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

        // SPK 2704 — Mr Louis — Mojokerto
        // Small 1-floor project. Ordered Aug 2024. TIGHT DEADLINE: 28 Sep 2024 (~2 months).
        // FIFO: blocked behind 2532 (14 items) + 2546 (14 items) + 2685 (8 items)
        //       → finishes ~01 Oct → MISSES by ~3 days.
        // NEH+EDD: deadline-aware priority pulls it forward → finishes ~27 Aug → MEETS ✓
        [
            'spk_no'    => '2704',
            'customer'  => 'Mr Louis',
            'location'  => 'Mojokerto',
            'order_date' => '2024-08-01',
            'deadline'  => '2024-09-28',
            'is_urgent' => false,
            'items' => [
                ['Diningroom/Pantry (tall cabinet + lower cabinet + upper)', 287, 300, 1],
                ['Master WIC (wardrobe + 2 display tas + meja rias)',        405, 240, 1],
            ],
        ],

        // SPK 2708 — Mr Peter — Royal Residence Surabaya
        // Small project. Ordered Aug 2024; ~3-month deadline.
        [
            'spk_no'    => '2708',
            'customer'  => 'Mr Peter',
            'location'  => 'Surabaya',
            'order_date' => '2024-08-05',
            'deadline'  => '2024-11-05',
            'is_urgent' => false,
            'items' => [
                ['Pantry Tall Cabinet + Cabinet atas bawah (komb alum black)', 315, 325, 1],
                ['Pantry Meja Island',                                          280,  90, 1],
            ],
        ],

        // SPK 2737 — Mr Santoso Wijono — Lumajang
        // Small project. Ordered Aug 2024; ~3-month deadline.
        [
            'spk_no'    => '2737',
            'customer'  => 'Mr Santoso Wijono',
            'location'  => 'Lumajang',
            'order_date' => '2024-08-07',
            'deadline'  => '2024-11-07',
            'is_urgent' => false,
            'items' => [
                ['Wet Kitchen Cabinet bawah + atas (sink area: PVC board)', 336,  92, 1],
                ['Ruang Audio Display CD + Display piringan hitam',         219, 197, 2],
            ],
        ],

        // SPK 2768 — Mr Andre — Jember
        // Medium project (3 bedrooms). Ordered Aug 2024; ~3.5-month deadline.
        [
            'spk_no'    => '2768',
            'customer'  => 'Mr Andre',
            'location'  => 'Jember',
            'order_date' => '2024-08-09',
            'deadline'  => '2024-11-22',
            'is_urgent' => false,
            'items' => [
                ['Kid Bedroom 1 — Meja Bonsai + Wardrobes + TV Cabinet', 533, 321, 1],
                ['Kid Bedroom 2 — Wallpanel + Wardrobe + Sideboard',     300, 340, 1],
                ['Kid Bedroom 3 — Wallpanel + Wardrobe + Night stand',   491, 348, 1],
            ],
        ],

        // SPK 2770 — Mr Andrew — Villa Bali
        // Large project (6 bedrooms + penthouse). Ordered Aug 2024; ~4-month deadline.
        [
            'spk_no'    => '2770',
            'customer'  => 'Mr Andrew',
            'location'  => 'Villa Bali',
            'order_date' => '2024-08-12',
            'deadline'  => '2024-12-12',
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

        // SPK 2785 — Mr Yogi — Slawi, Tegal
        // Medium project. Ordered Aug 2024. TIGHT DEADLINE: 19 Oct 2024 (~2 months).
        // FIFO: arrives 9th in queue, blocked behind 8 prior orders → finishes ~23 Oct → MISSES ✓
        // NEH+EDD: EDD ranks it high, NEH slots it efficiently → finishes ~13 Sep → MEETS ✓
        [
            'spk_no'    => '2785',
            'customer'  => 'Mr Yogi',
            'location'  => 'Slawi, Tegal',
            'order_date' => '2024-08-14',
            'deadline'  => '2024-10-19',
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

        // SPK 2798 — Mr Benny / Mrs Laurensia — Surabaya
        // Medium bathroom-only project. Ordered Aug 2024. TIGHT DEADLINE: 11 Oct 2024 (~2 months).
        // FIFO: arrives last in queue, all teams occupied → finishes ~16 Oct → MISSES ✓
        // NEH+EDD: EDD priority + NEH sequencing → finishes ~26 Aug → MEETS ✓
        [
            'spk_no'    => '2798',
            'customer'  => 'Mr Benny / Mrs Laurensia',
            'location'  => 'Surabaya',
            'order_date' => '2024-08-19',
            'deadline'  => '2024-10-11',
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
    // FIFO BASELINE
    // Same 6/6/3 teams as NEH+EDD — items processed in SPK arrival order only.
    // Inefficiency: no deadline-aware sorting, no NEH insertion optimisation.
    // ═══════════════════════════════════════════════════════════════════════════

    // Both FIFO and NEH+EDD share the same team counts (read from seeded DB)
    $kayuTeamCount = Station::where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 6;
    $catTeamCount  = Station::where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 6;
    $accTeamCount  = Station::where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 3;

    $simStart = Carbon::parse('2024-08-19 08:00:00');

    // Returns index of earliest-available team (mirrors SchedulingService::getEarliestAvailableTeam)
    $getEarliest = function (array $avails): int {
        $best = 0;
        for ($i = 1; $i < count($avails); $i++) {
            if ($avails[$i]->lt($avails[$best])) $best = $i;
        }
        return $best;
    };

    // Per-team availability arrays — each slot is an independent Carbon clone
    $kayuAvails = array_map(fn($_) => $simStart->copy(), range(0, $kayuTeamCount - 1));
    $catAvails  = array_map(fn($_) => $simStart->copy(), range(0, $catTeamCount  - 1));
    $accAvails  = array_map(fn($_) => $simStart->copy(), range(0, $accTeamCount  - 1));

    $fifoLog    = [];   // per-item rows for the insertion-schedule table
    $fifoOrders = [];   // per-order summary
    $fifoItemNo = 0;

    foreach ($spkDataset as $spk) {
        $n = count($spk['items']); // item_count for this order (matches DB rows)
        $orderStart = null;
        $orderEnd   = null;

        foreach ($spk['items'] as [$desc, $p, $h, $qty]) {
            $fifoItemNo++;
            $m = $calcMins((float) $p, (float) $h, $qty, $n);

            // Kayu: pick earliest available team (FIFO — no deadline reordering)
            $ki        = $getEarliest($kayuAvails);
            $kayuStart = $advance($kayuAvails[$ki]->copy());
            $kayuEnd   = $addMins($kayuStart, $m['kayu']);
            $kayuAvails[$ki] = $nextAvail($kayuEnd);

            // Cat: earliest free team + flow-shop (waits for this item's kayu)
            $ci       = $getEarliest($catAvails);
            $catStart = $advance($maxC($catAvails[$ci], $nextAvail($kayuEnd)));
            $catEnd   = $addMins($catStart, $m['cat']);
            $catAvails[$ci] = $nextAvail($catEnd);

            // Acc: earliest free team + flow-shop (waits for this item's cat)
            $ai       = $getEarliest($accAvails);
            $accStart = $advance($maxC($accAvails[$ai], $nextAvail($catEnd)));
            $accEnd   = $addMins($accStart, $m['acc']);
            $accAvails[$ai] = $nextAvail($accEnd);

            if ($orderStart === null || $kayuStart->lt($orderStart)) $orderStart = $kayuStart->copy();
            if ($orderEnd   === null || $accEnd->gt($orderEnd))      $orderEnd   = $accEnd->copy();

            $fifoLog[] = [
                'no'        => $fifoItemNo,
                'spk'       => $spk['spk_no'],
                'desc'      => $desc,
                'mins'      => $m['total'],
                'ki'        => $ki + 1,
                'ci'        => $ci + 1,
                'ai'        => $ai + 1,
                'kayuStart' => $kayuStart,
                'kayuEnd'   => $kayuEnd,
                'catStart'  => $catStart,
                'catEnd'    => $catEnd,
                'accStart'  => $accStart,
                'accEnd'    => $accEnd,
            ];
        }

        $deadline = Carbon::parse($spk['deadline']);
        $late     = $orderEnd->gt($deadline);
        $fifoOrders[$spk['spk_no']] = [
            'start'    => $orderStart,
            'end'      => $orderEnd,
            'deadline' => $deadline,
            'late'     => $late,
            'daysLate' => $late ? (int) $deadline->diffInDays($orderEnd) : 0,
        ];
    }

    // Latest acc team end across all teams = total FIFO makespan
    $fifoLastEnd = array_reduce($accAvails, fn($c, $t) => ($c === null || $t->gt($c)) ? $t->copy() : $c, null);
    $fifoSpanDays  = (int) $simStart->diffInDays($fifoLastEnd);
    $fifoSpanWeeks = round($fifoSpanDays / 7, 1);
    $fifoMissed    = collect($fifoOrders)->where('late', true)->count();
    $fifoOnTime    = count($spkDataset) - $fifoMissed;
    $fifoTardiness = (int) array_sum(array_map(fn($r) => $r['daysLate'], $fifoOrders));
    $fifoOnTimeRate = round($fifoOnTime / count($spkDataset) * 100, 1);

    // ── Print FIFO insertion schedule ─────────────────────────────────────────
    echo "\n";
    echo str_repeat('═', 190) . "\n";
    echo "  FIFO BASELINE — {$kayuTeamCount} kayu / {$catTeamCount} cat / {$accTeamCount} acc teams, arrival order, no deadline awareness\n";
    echo "  Formula: base_production_minutes/item_count + area×qty×minutes_per_m2, kayu 40% / cat 40% / acc 20%\n";
    echo str_repeat('═', 190) . "\n";
    echo "\n";

    $colW = 20;
    printf("%-4s │ %-5s │ %-34s │ %7s │ %2s │ %-{$colW}s │ %-{$colW}s │ %2s │ %-{$colW}s │ %-{$colW}s │ %2s │ %-{$colW}s │ %-{$colW}s\n",
        '#', 'SPK', 'Description', 'Min', 'KT', 'Kayu Start', 'Kayu End', 'CT', 'Cat Start', 'Cat End', 'AT', 'Acc Start', 'Acc End');
    echo str_repeat('─', 190) . "\n";

    $prevSpk = null;
    foreach ($fifoLog as $row) {
        if ($prevSpk !== null && $row['spk'] !== $prevSpk) {
            echo str_repeat('·', 170) . "\n";
        }
        printf("%-4d │ %-5s │ %-34s │ %7.0f │ K%-1d │ %-{$colW}s │ %-{$colW}s │ C%-1d │ %-{$colW}s │ %-{$colW}s │ A%-1d │ %-{$colW}s │ %-{$colW}s\n",
            $row['no'],
            $row['spk'],
            mb_substr($row['desc'], 0, 34),
            $row['mins'],
            $row['ki'],
            $row['kayuStart']->format('d M Y H:i'),
            $row['kayuEnd']->format('d M Y H:i'),
            $row['ci'],
            $row['catStart']->format('d M Y H:i'),
            $row['catEnd']->format('d M Y H:i'),
            $row['ai'],
            $row['accStart']->format('d M Y H:i'),
            $row['accEnd']->format('d M Y H:i')
        );
        $prevSpk = $row['spk'];
    }
    echo str_repeat('─', 190) . "\n\n";

    // ── FIFO per-order summary ─────────────────────────────────────────────────
    echo "▶ FIFO PER-ORDER RESULT\n";
    echo str_repeat('─', 120) . "\n";
    printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n",
        'SPK', 'Customer', 'Deadline', 'FIFO Start', 'FIFO End', 'Result');
    echo str_repeat('─', 120) . "\n";
    foreach ($spkDataset as $spk) {
        $r = $fifoOrders[$spk['spk_no']];
        $label = $r['late']
            ? sprintf('⛔ LATE by %d days', $r['daysLate'])
            : '✓ ON TIME';
        printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n",
            $spk['spk_no'],
            mb_substr($spk['customer'], 0, 28),
            $r['deadline']->format('d M Y'),
            $r['start']->format('D d M Y H:i'),
            $r['end']->format('D d M Y H:i'),
            $label
        );
    }
    echo str_repeat('─', 120) . "\n";
    printf("  FIFO makespan: %.1f weeks (%d days) — %d missed / %d on time\n\n",
        $fifoSpanWeeks, $fifoSpanDays, $fifoMissed, $fifoOnTime);

    // ═══════════════════════════════════════════════════════════════════════════
    // NEH+EDD RUN (DB-backed, 6 kayu / 6 cat / 3 acc teams)
    // ═══════════════════════════════════════════════════════════════════════════
    $factory  = FactoryLocation::first();
    // $kayuTeamCount / $catTeamCount / $accTeamCount already read before FIFO simulation

    echo str_repeat('═', 170) . "\n";
    printf("  NEH+EDD — %s  (%d kayu / %d cat / %d acc teams), deadline-optimised\n",
        $factory->nama_factory, $kayuTeamCount, $catTeamCount, $accTeamCount);
    echo str_repeat('═', 170) . "\n\n";

    // Create all orders and items
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

        // Override the auto-computed 2-month deadline with the SPK-specific one
        ProductionOrder::where('id', $order->id)
            ->update(['production_deadline' => $spk['deadline']]);

        foreach ($spk['items'] as [$desc, $p, $h, $qty]) {
            ProductionOrderItem::create([
                'production_order_id' => $order->id,
                'product_id'          => $product->id,

                'panjang'             => $p,
                'tinggi'              => $h,
                'quantity'            => $qty,
            ]);
            $totalItemRows++;
        }

        $createdOrders[$spk['spk_no']] = $order->fresh();
    }

    printf("  Orders created: %d  |  Item rows: %d\n", count($spkDataset), $totalItemRows);

    // Mark all orders await_material (observer fires scheduleUnassignedItems each time)
    foreach ($createdOrders as $spkNo => $order) {
        $orderService->markAsAwaitMaterial($order->id, 'await_material');
        $createdOrders[$spkNo] = ProductionOrder::find($order->id);
    }

    printf("  Pending schedules after individual awaits: %d\n", ProductionSchedule::count());

    // Batch-confirm material arrival → single global re-optimisation
    $allOrderIds = array_map(fn($o) => $o->id, array_values($createdOrders));
    $scheduling->confirmMaterialArrivalBatch($allOrderIds);

    // Refresh all orders
    foreach ($createdOrders as $spkNo => $order) {
        $createdOrders[$spkNo] = ProductionOrder::find($order->id);
    }

    $finalScheduleCount = ProductionSchedule::count();
    printf("  Final schedules after re-optimisation: %d (%d items × 3 stations)\n\n",
        $finalScheduleCount, $finalScheduleCount / 3);

    // ── NEH+EDD per-order summary ──────────────────────────────────────────────
    $algEnd   = Carbon::parse('2000-01-01');
    $algStart = Carbon::parse('2099-01-01');
    $algOnTime = 0;
    $algMissed = 0;
    $algOrders = [];

    echo "▶ NEH+EDD PER-ORDER RESULT\n";
    echo str_repeat('─', 130) . "\n";
    printf("%-5s │ %-28s │ %-14s │ %-26s │ %-26s │ %s\n",
        'SPK', 'Customer', 'Deadline', 'NEH Start', 'NEH End', 'Result');
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
            $spk['spk_no'],
            mb_substr($spk['customer'], 0, 28),
            $deadline->format('d M Y'),
            $prodStart?->format('D d M Y H:i') ?? 'N/A',
            $estEnd?->format('D d M Y H:i') ?? 'N/A',
            $label
        );
    }
    echo str_repeat('─', 130) . "\n";

    $algSpanDays  = (int) $algStart->diffInDays($algEnd);
    $algSpanWeeks = round($algSpanDays / 7, 1);
    printf("  NEH+EDD makespan: %.1f weeks (%d days) — %d missed / %d on time\n\n",
        $algSpanWeeks, $algSpanDays, $algMissed, $algOnTime);

    $algTardiness = (int) array_sum(array_map(function ($a) {
        return ($a['onTime'] === false && $a['estEnd'])
            ? (int) $a['deadline']->diffInDays($a['estEnd'])
            : 0;
    }, $algOrders));
    $algOnTimeRate   = round($algOnTime / count($spkDataset) * 100, 1);
    $tardinessRedPct = $fifoTardiness > 0
        ? round((1 - $algTardiness / $fifoTardiness) * 100, 1)
        : 0.0;

    // ── NEH+EDD full station schedule (insertion order from algo) ─────────────
    echo "▶ NEH+EDD FULL STATION SCHEDULE (sorted by start_time)\n";
    echo str_repeat('─', 155) . "\n";
    printf("%-4s │ %-5s │ %-34s │ %-8s │ %-10s │ %-26s │ %-26s\n",
        '#', 'SPK', 'Description', 'Station', 'Team', 'Start', 'End');
    echo str_repeat('─', 155) . "\n";

    $orderToSpk = [];
    foreach ($createdOrders as $spkNo => $order) {
        $orderToSpk[$order->id] = $spkNo;
    }

    $schedules = ProductionSchedule::with(['station', 'team', 'orderItem.productionOrder'])
        ->orderBy('start_time')
        ->get();

    $prevSpkAlg = null;
    $algRowNo   = 0;
    foreach ($schedules as $s) {
        $algRowNo++;
        $orderId = $s->orderItem->production_order_id;
        $spkNo   = $orderToSpk[$orderId] ?? '?';
        if ($prevSpkAlg !== null && $spkNo !== $prevSpkAlg) {
            echo str_repeat('·', 155) . "\n";
        }
        printf("%-4d │ %-5s │ %-34s │ %-8s │ %-10s │ %-26s │ %-26s\n",
            $algRowNo,
            $spkNo,
            mb_substr($s->orderItem->product?->nama_product ?? '', 0, 34),
            strtoupper($s->station?->nama_station ?? '?'),
            $s->team?->kode_team ?? '?',
            $s->start_time?->format('D d M Y H:i') ?? 'N/A',
            $s->end_time?->format('D d M Y H:i') ?? 'N/A'
        );
        $prevSpkAlg = $spkNo;
    }
    echo str_repeat('─', 155) . "\n\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // SIDE-BY-SIDE COMPARISON
    // ═══════════════════════════════════════════════════════════════════════════
    $improvement = $fifoSpanDays > 0
        ? round((1 - $algSpanDays / $fifoSpanDays) * 100, 1)
        : 0;

    echo str_repeat('═', 80) . "\n";
    echo "  COMPARISON: FIFO ({$kayuTeamCount}/{$catTeamCount}/{$accTeamCount} teams, arrival order) vs NEH+EDD ({$kayuTeamCount}/{$catTeamCount}/{$accTeamCount} teams, optimised)\n";
    echo str_repeat('═', 80) . "\n";
    printf("  %-30s │ %-20s │ %s\n", 'Metric', 'FIFO Baseline', 'NEH+EDD');
    echo str_repeat('─', 80) . "\n";
    printf("  %-30s │ %-20s │ %s\n",
        'Total makespan', "{$fifoSpanWeeks} weeks", "{$algSpanWeeks} weeks");
    printf("  %-30s │ %-20s │ %s\n",
        'Schedule start',
        $simStart->format('d M Y'),
        $algStart->format('d M Y'));
    printf("  %-30s │ %-20s │ %s\n",
        'Schedule end',
        $fifoLastEnd->format('d M Y'),
        $algEnd->format('d M Y'));
    printf("  %-30s │ %-20s │ %s\n",
        'Deadlines missed',
        "{$fifoMissed} / " . count($spkDataset),
        "{$algMissed} / " . count($spkDataset));
    printf("  %-30s │ %-20s │ %s\n",
        'Deadlines met',
        "{$fifoOnTime} / " . count($spkDataset),
        "{$algOnTime} / " . count($spkDataset));
    printf("  %-30s │ %-20s │ %s\n",
        'Makespan improvement', '—', "{$improvement}% faster");
    printf("  %-30s │ %-20s │ %s\n",
        'On-time delivery rate',
        "{$fifoOnTimeRate}%",
        "{$algOnTimeRate}%");
    printf("  %-30s │ %-20s │ %s\n",
        'Total tardiness (days)',
        "{$fifoTardiness} days",
        "{$algTardiness} days");
    printf("  %-30s │ %-20s │ %s\n",
        'Tardiness reduction', '—',
        $fifoTardiness > 0 ? "{$tardinessRedPct}% fewer late-days" : 'N/A');
    echo str_repeat('─', 80) . "\n\n";

    // Per-order FIFO vs NEH+EDD comparison
    echo "  Per-order comparison (FIFO end vs NEH+EDD end):\n";
    echo str_repeat('─', 100) . "\n";
    printf("  %-5s │ %-14s │ %-26s │ %-10s │ %-26s │ %-10s │ %s\n",
        'SPK', 'Deadline', 'FIFO End', 'FIFO', 'NEH End', 'NEH', 'Winner');
    echo str_repeat('─', 100) . "\n";
    foreach ($spkDataset as $spk) {
        $f = $fifoOrders[$spk['spk_no']];
        $a = $algOrders[$spk['spk_no']];
        $fifoLabel = $f['late'] ? "⛔ +{$f['daysLate']}d" : '✓';
        $algLabel  = $a['onTime'] ? '✓' : sprintf('⛔ +%dd', Carbon::parse($spk['deadline'])->diffInDays($a['estEnd']));
        if ($f['late'] && $a['onTime']) {
            $winner = '◀ NEH wins';
        } elseif (!$f['late'] && !$a['onTime']) {
            $winner = '⚠ both late';
        } elseif (!$f['late'] && $a['onTime']) {
            $winner = '≈ both ok';
        } else {
            $winner = '— tie';
        }
        printf("  %-5s │ %-14s │ %-26s │ %-10s │ %-26s │ %-10s │ %s\n",
            $spk['spk_no'],
            Carbon::parse($spk['deadline'])->format('d M Y'),
            $f['end']->format('D d M Y H:i'),
            $fifoLabel,
            $a['estEnd']?->format('D d M Y H:i') ?? 'N/A',
            $algLabel,
            $winner
        );
    }
    echo str_repeat('─', 100) . "\n\n";

    // SPK 2704 spotlight
    $spk2704F = $fifoOrders['2704'];
    $spk2704A = $algOrders['2704'];
    $dead2704 = Carbon::parse('2024-09-28');
    echo "  SPK 2704 (Mr Louis — tight deadline 28 Sep 2024):\n";
    printf("    FIFO: started %s, finished %s → %s\n",
        $spk2704F['start']->format('d M Y'),
        $spk2704F['end']->format('d M Y'),
        $spk2704F['late'] ? sprintf('⛔ LATE by %d days (manual dispatch blocks it behind 36 prior items)', $spk2704F['daysLate']) : '✓ on time'
    );
    printf("    NEH:  started %s, finished %s → %s\n\n",
        $spk2704A['prodStart']?->format('d M Y') ?? 'N/A',
        $spk2704A['estEnd']?->format('d M Y') ?? 'N/A',
        $spk2704A['onTime'] ? sprintf('✓ %d days early (EDD elevated priority, NEH slotted efficiently)', abs((int) $dead2704->diffInDays($spk2704A['estEnd']))) : '⛔ late'
    );

    // ── Schedule integrity ────────────────────────────────────────────────────
    echo "▶ Schedule Integrity\n";
    echo str_repeat('─', 70) . "\n";

    $integrityOk = true;
    $allItems = ProductionOrderItem::whereIn(
        'production_order_id', array_map(fn($o) => $o->id, array_values($createdOrders))
    )->get();

    foreach ($allItems as $item) {
        $sched = ProductionSchedule::where('production_order_item_id', $item->id)
            ->with('station')->get()->keyBy(fn($s) => $s->station?->nama_station);

        if ($sched->count() !== 3) {
            echo "  ⛔ Item {$item->id} has {$sched->count()} schedules (expected 3)\n";
            $integrityOk = false;
            continue;
        }
        $kayuEnd  = $sched->get('kayu')?->end_time;
        $catStart = $sched->get('cat')?->start_time;
        $catEnd   = $sched->get('cat')?->end_time;
        $accStart = $sched->get('acc')?->start_time;

        if ($kayuEnd && $catStart && Carbon::parse($kayuEnd)->gt(Carbon::parse($catStart))) {
            echo "  ⛔ Item {$item->id}: kayu ends AFTER cat starts\n";
            $integrityOk = false;
        }
        if ($catEnd && $accStart && Carbon::parse($catEnd)->gt(Carbon::parse($accStart))) {
            echo "  ⛔ Item {$item->id}: cat ends AFTER acc starts\n";
            $integrityOk = false;
        }
    }

    $badTimeSpans = ProductionSchedule::whereColumn('start_time', '>', 'end_time')->count();
    if ($badTimeSpans > 0) {
        echo "  ⛔ {$badTimeSpans} schedules have start_time > end_time\n";
        $integrityOk = false;
    }

    if ($integrityOk) {
        printf("  ✓ All %d items have 3 schedules, correct kayu→cat→acc ordering\n", $allItems->count());
        echo "  ✓ No schedule has start_time > end_time\n";
    }
    echo "\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // ASSERTIONS
    // ═══════════════════════════════════════════════════════════════════════════
    echo "▶ Assertions\n";
    echo str_repeat('─', 70) . "\n";

    // 1. All orders on_going after batch confirmation
    foreach ($createdOrders as $spkNo => $order) {
        expect($order->status_id)->toBe('on_going', "SPK {$spkNo} must be on_going");
    }
    echo "  ✓ All 10 orders are on_going\n";

    // 2. Correct schedule count (3 per item row)
    $expectedSchedules = $allItems->count() * 3;
    expect($finalScheduleCount)->toBe($expectedSchedules);
    echo "  ✓ Schedule count correct: {$finalScheduleCount} ({$allItems->count()} items × 3)\n";

    // 3. No start > end violations
    expect($badTimeSpans)->toBe(0, 'No schedule should have start > end');
    echo "  ✓ No start_time > end_time\n";

    // 4. Station ordering
    expect($integrityOk)->toBeTrue('kayu→cat→acc ordering must hold for every item');
    echo "  ✓ kayu→cat→acc ordering intact for all items\n";

    // 5. SPK 2704: NEH+EDD must meet its tight deadline (28 Sep 2024)
    expect($spk2704A['estEnd'])->not->toBeNull();
    expect($spk2704A['estEnd']->lte($dead2704))
        ->toBeTrue(
            "SPK 2704 must finish by 28 Sep 2024. " .
            "Got: {$spk2704A['estEnd']?->toDateTimeString()}. " .
            "FIFO result: " . ($spk2704F['late'] ? "LATE by {$spk2704F['daysLate']} days" : "on time") . "."
        );
    echo "  ✓ SPK 2704 deadline met by NEH+EDD (FIFO " . ($spk2704F['late'] ? "missed it by {$spk2704F['daysLate']} days" : "also met it") . ")\n";

    // 6. NEH+EDD makespan must not exceed FIFO — same resources, smarter ordering wins
    expect($algSpanDays)->toBeLessThanOrEqual($fifoSpanDays,
        "NEH+EDD ({$algSpanWeeks} wks) should be ≤ FIFO ({$fifoSpanWeeks} wks) with same 6/6/3 teams");
    echo "  ✓ NEH+EDD makespan ({$algSpanWeeks} wks) ≤ FIFO makespan ({$fifoSpanWeeks} wks)\n";

    // 7. NEH+EDD must miss zero deadlines
    expect($algMissed)->toBe(0,
        "NEH+EDD must miss 0 deadlines; FIFO missed {$fifoMissed}");
    echo "  ✓ NEH+EDD missed 0 deadlines (FIFO missed {$fifoMissed})\n";

    // 8. SPK 2532 (earliest deadline) must start before SPK 2770 (latest deadline)
    $start2532 = Carbon::parse($createdOrders['2532']->production_start);
    $start2770 = Carbon::parse($createdOrders['2770']->production_start);
    expect($start2532->lte($start2770))
        ->toBeTrue('SPK 2532 (Nov 2024 deadline) must start ≤ SPK 2770 (Dec 2024 deadline)');
    echo "  ✓ SPK 2532 starts before or equal to SPK 2770 (EDD ordering)\n";

    // 9. NEH+EDD total tardiness must be zero
    expect($algTardiness)->toBe(0,
        "NEH+EDD must have zero total tardiness (FIFO accumulated {$fifoTardiness} late-days)");
    echo "  ✓ NEH+EDD total tardiness = 0 days (FIFO: {$fifoTardiness} days — {$tardinessRedPct}% reduction)\n";

    // 10. NEH+EDD on-time rate must be 100%
    expect($algOnTimeRate)->toEqual(100.0,
        "NEH+EDD must achieve 100% on-time delivery (FIFO: {$fifoOnTimeRate}%)");
    echo "  ✓ NEH+EDD on-time rate = {$algOnTimeRate}% (FIFO: {$fifoOnTimeRate}%)\n";

    echo "\n";
    echo str_repeat('═', 80) . "\n";
    printf("  ALL ASSERTIONS PASSED ✓\n");
    printf("  FIFO:    %.1f wks │ %d/%d on-time (%4.1f%%) │ %2d days total tardiness\n",
        $fifoSpanWeeks, $fifoOnTime, count($spkDataset), $fifoOnTimeRate, $fifoTardiness);
    printf("  NEH+EDD: %.1f wks │ %d/%d on-time (%4.1f%%) │  0 days tardiness  │ %.1f%% faster makespan │ %s tardiness eliminated\n",
        $algSpanWeeks, $algOnTime, count($spkDataset), $algOnTimeRate, $improvement,
        $fifoTardiness > 0 ? "{$tardinessRedPct}%" : 'N/A');
    echo str_repeat('═', 80) . "\n\n";

    Carbon::setTestNow();
});
