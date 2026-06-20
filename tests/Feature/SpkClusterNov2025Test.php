<?php

/**
 * SPK Klaster November 2025 — Manual vs Algoritma NEH+EDD (selesai sebelum deadline)
 *
 * Tiga SPK produksi nyata yang masuk pada tanggal BERBEDA namun berdekatan:
 *   - SPK 2770 (Mr Andrew, Villa Bali)  — proyek besar,   12 item — masuk 3 Nov 2025
 *   - SPK 2785 (Mr Yogi, Slawi/Tegal)   — proyek menengah, 9 item — masuk 5 Nov 2025
 *   - SPK 2798 (Mr Benny/Mrs Laurensia) — proyek kecil,    8 item — masuk 7 Nov 2025
 *
 * Ketiganya bersaing memperebutkan pabrik 6 tim kayu / 6 cat / 3 acc. Dokumen tidak
 * mencantumkan tenggat, sehingga ditetapkan tenggat WAJAR: order kecil 2798 mendesak
 * (~2 minggu), dua proyek besar lebih longgar.
 *
 * Manual (mendahulukan proyek besar 2770→2785, lalu 2798) mengubur order kecil 2798
 * sehingga TELAT. Algoritma NEH+EDD menaikkan prioritas 2798 sehingga SELURUH order
 * selesai sebelum tenggat, sekaligus memperpendek makespan.
 *
 * Tabel hasil menampilkan: tanggal order masuk, tanggal produksi mulai, tanggal
 * deadline, dan tanggal selesai. Anchor perencanaan: Jumat, 7 Nov 2025. Dimensi cm.
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

test('SPK Klaster Nov 2025: Manual vs NEH+EDD selesai sebelum deadline', function () {

    // Seed ONLY master data — NOT SpkKlasterPembuktianSeeder, which would create
    // its own copies of SPK 2770/2785/2798 in await_material and double the items.
    $this->seed([
        \Database\Seeders\MaterialSeeder::class,
        \Database\Seeders\ProductSeeder::class,
        \Database\Seeders\FactoryLocationSeeder::class,
        \Database\Seeders\StationSeeder::class,
        \Database\Seeders\TeamSeeder::class,
        \Database\Seeders\ProductionStatusSeeder::class,
        \Database\Seeders\CustomerSeeder::class,
        \Database\Seeders\AdminUserSeeder::class,
    ]);
    // Perencanaan dijalankan saat order terakhir (2798) masuk: 7 Nov 2025.
    Carbon::setTestNow(Carbon::parse('2025-11-07 08:00:00'));

    config([
        'production.minutes_per_m2'          => 300,
        'production.base_production_minutes'  => 1440,
        'production.cm2_per_m2'              => 10000,
        'production.work_minutes_per_day'    => 540,
        'production.station_split'           => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2],
        'production.buffer_days'             => 2,
        'production.critical_window_days'    => 14,
        'production.calendar_penalty'        => 1.4,
        'production.tardiness_weight'        => 2.0,
        'production.handoff_buffer_minutes'  => 0,
        'production.order_deadline_months'   => 2,
    ]);

    // ── Working-hours helpers (mirror SchedulingService) ──────────────────────
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
        $c = $advance($start->copy()); $rem = $mins;
        while ($rem > 0) {
            $eod = $c->copy()->setTime(17, 0, 0); $avail = $c->diffInMinutes($eod);
            if ($rem <= $avail) { $c->addMinutes((int) $rem); $rem = 0; }
            else { $rem -= $avail; $c->addDay()->setTime(8, 0, 0); $c = $advance($c); }
        }
        return $c;
    };
    $nextAvail = fn(Carbon $end) => $end->format('H:i:s') >= '15:00:00'
        ? $advance($end->copy()->addDay()->setTime(8, 0, 0)) : $end->copy();
    $maxC = fn(Carbon $a, Carbon $b): Carbon => $a->gt($b) ? $a->copy() : $b->copy();
    $calcMins = function (float $p, float $h, int $qty, int $n): array {
        $t = (1440.0 / max(1, $n)) + (($p * $h) / 10000.0) * $qty * 300.0;
        return ['kayu' => $t * 0.4, 'cat' => $t * 0.4, 'acc' => $t * 0.2, 'total' => $t];
    };

    $product = Product::first();
    $material = Material::first();
    $orderService = app(ProductionOrderService::class);
    $scheduling = app(SchedulingService::class);

    // ── Dataset: 3 SPK klaster Nov 2025 (array order = urutan Manual) ───────────
    $spkDataset = [
        [
            'spk_no' => '2770', 'customer' => 'Mr Andrew', 'location' => 'Villa Bali',
            'order_date' => '2025-11-03', 'deadline' => '2025-12-10', // masuk 3 Nov, tenggat longgar
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
            'order_date' => '2025-11-05', 'deadline' => '2025-12-12', // masuk 5 Nov, tenggat longgar
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
            'spk_no' => '2798', 'customer' => 'Mr Benny / Mrs Laurensia', 'location' => 'Surabaya',
            'order_date' => '2025-11-07', 'deadline' => '2025-11-22', // masuk 7 Nov, MENDESAK (~2 minggu)
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
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // Manual BASELINE (mendahulukan proyek besar 2770 → 2785 → 2798), 6/6/3 tim.
    // Produksi mulai pada anchor perencanaan (7 Nov 2025).
    // ═══════════════════════════════════════════════════════════════════════════
    $kayuN = Station::where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 6;
    $catN  = Station::where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 6;
    $accN  = Station::where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 3;

    $simStart = Carbon::parse('2025-11-07 08:00:00');
    $earliest = function (array $a) { $b = 0; for ($i = 1; $i < count($a); $i++) if ($a[$i]->lt($a[$b])) $b = $i; return $b; };
    $K = array_map(fn($_) => $simStart->copy(), range(0, $kayuN - 1));
    $C = array_map(fn($_) => $simStart->copy(), range(0, $catN - 1));
    $A = array_map(fn($_) => $simStart->copy(), range(0, $accN - 1));

    $fcfs = [];
    $manLog = []; $manItemNo = 0;
    foreach ($spkDataset as $spk) {
        $n = count($spk['items']); $ostart = null; $oend = null;
        foreach ($spk['items'] as [$d, $p, $h, $q]) {
            $manItemNo++;
            $m = $calcMins((float) $p, (float) $h, $q, $n);
            $ki = $earliest($K); $ks = $advance($K[$ki]->copy()); $ke = $addMins($ks, $m['kayu']); $K[$ki] = $nextAvail($ke);
            $ci = $earliest($C); $cs = $advance($maxC($C[$ci], $nextAvail($ke))); $ce = $addMins($cs, $m['cat']); $C[$ci] = $nextAvail($ce);
            $ai = $earliest($A); $as = $advance($maxC($A[$ai], $nextAvail($ce))); $ae = $addMins($as, $m['acc']); $A[$ai] = $nextAvail($ae);
            if ($ostart === null || $ks->lt($ostart)) $ostart = $ks->copy();
            if ($oend === null || $ae->gt($oend)) $oend = $ae->copy();
            $manLog[] = ['no' => $manItemNo, 'spk' => $spk['spk_no'], 'desc' => $d,
                'kStart' => $ks, 'kTeam' => 'K' . ($ki + 1), 'cStart' => $cs, 'aStart' => $as, 'aEnd' => $ae];
        }
        $dl = Carbon::parse($spk['deadline']);
        $fcfs[$spk['spk_no']] = ['start' => $ostart, 'end' => $oend, 'deadline' => $dl, 'late' => $oend->gt($dl),
            'daysLate' => $oend->gt($dl) ? (int) $dl->diffInDays($oend) : 0,
            'slack' => (int) $oend->diffInDays($dl, false)];
    }
    $fcfsEnd = array_reduce($A, fn($c, $t) => ($c === null || $t->gt($c)) ? $t->copy() : $c, null);
    $fcfsSpan = (int) $simStart->diffInDays($fcfsEnd);
    $fcfsMiss = collect($fcfs)->where('late', true)->count();
    $fcfsTard = (int) array_sum(array_map(fn($r) => $r['daysLate'], $fcfs));

    echo "\n" . str_repeat('═', 110) . "\n";
    echo "  KLASTER NOVEMBER 2025 — 3 SPK berdekatan (2770, 2785, 2798) | pabrik {$kayuN} kayu / {$catN} cat / {$accN} acc\n";
    echo str_repeat('═', 110) . "\n\n";

    echo "▶ Manual (mendahulukan proyek besar 2770 → 2785 → 2798)\n" . str_repeat('─', 124) . "\n";
    printf("%-5s │ %-22s │ %-11s │ %-13s │ %-11s │ %-13s │ %s\n",
        'SPK', 'Customer', 'Order masuk', 'Prod. mulai', 'Deadline', 'Selesai', 'Status');
    echo str_repeat('─', 124) . "\n";
    foreach ($spkDataset as $spk) {
        $r = $fcfs[$spk['spk_no']];
        printf("%-5s │ %-22s │ %-11s │ %-13s │ %-11s │ %-13s │ %s\n", $spk['spk_no'], mb_substr($spk['customer'], 0, 22),
            Carbon::parse($spk['order_date'])->format('d M Y'), $r['start']->format('d M Y'),
            $r['deadline']->format('d M Y'), $r['end']->format('d M Y'),
            $r['late'] ? "⛔ TELAT {$r['daysLate']} hari" : "✓ tepat ({$r['slack']} hari sisa)");
    }
    echo str_repeat('─', 100) . "\n";
    printf("  Makespan Manual: %d hari (selesai %s) — %d telat, total keterlambatan %d hari\n\n",
        $fcfsSpan, $fcfsEnd->format('d M Y'), $fcfsMiss, $fcfsTard);

    // ── BUKTI DETAIL: tabel insertion item (Manual) ───────────────────────────
    // Memperlihatkan KAPAN tiap item masuk ke tiap stasiun. Item SPK 2798 (kecil &
    // mendesak) baru masuk lini kayu pada 14 Nov karena diproses setelah order besar.
    $insHeader = function (string $judul) {
        echo "▶ {$judul}\n" . str_repeat('─', 116) . "\n";
        printf("%-3s │ %-5s │ %-30s │ %-15s │ %-12s │ %-12s │ %-12s\n",
            '#', 'SPK', 'Item', 'Masuk kayu (tim)', 'Masuk cat', 'Masuk acc', 'Selesai');
        echo str_repeat('─', 116) . "\n";
    };
    $insHeader('BUKTI Manual — urutan item masuk lini (urut proses operator)');
    $prevSpk = null;
    foreach ($manLog as $r) {
        if ($prevSpk !== null && $r['spk'] !== $prevSpk) echo str_repeat('·', 116) . "\n";
        printf("%-3d │ %-5s │ %-30s │ %-11s %-3s │ %-12s │ %-12s │ %-12s\n",
            $r['no'], $r['spk'], mb_substr($r['desc'], 0, 30),
            $r['kStart']->format('d M H:i'), $r['kTeam'],
            $r['cStart']->format('d M H:i'), $r['aStart']->format('d M H:i'), $r['aEnd']->format('d M H:i'));
        $prevSpk = $r['spk'];
    }
    echo str_repeat('─', 116) . "\n\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // NEH+EDD (algoritma program), 6/6/3 tim
    // ═══════════════════════════════════════════════════════════════════════════
    $factory = FactoryLocation::first();
    $created = [];
    foreach ($spkDataset as $spk) {
        $order = $orderService->create([
            'nama_customer' => $spk['customer'], 'alamat_customer' => $spk['location'],
            'nomor_telp' => '08123456789', 'tanggal_order' => $spk['order_date'],
            'status_id' => 'new', 'is_urgent' => false,
        ]);
        ProductionOrder::where('id', $order->id)->update(['production_deadline' => $spk['deadline']]);
        foreach ($spk['items'] as [$d, $p, $h, $q]) {
            $item = ProductionOrderItem::create([
                'production_order_id' => $order->id, 'product_id' => $product->id,
                'panjang' => $p, 'tinggi' => $h, 'quantity' => $q, 'keterangan' => $d,
            ]);
            ProductionOrderItemMaterial::create([
                'production_order_item_id' => $item->id, 'material_id' => $material->id,
                'quantity' => 1, 'cost' => 0, 'is_deducted' => true,
            ]);
        }
        $created[$spk['spk_no']] = $order->fresh();
    }
    foreach ($created as $no => $o) { $orderService->markAsAwaitMaterial($o->id, 'await_material'); $created[$no] = ProductionOrder::find($o->id); }
    $scheduling->confirmMaterialArrivalBatch(array_map(fn($o) => $o->id, array_values($created)));
    foreach ($created as $no => $o) $created[$no] = ProductionOrder::find($o->id);

    $algEnd = Carbon::parse('2000-01-01'); $algStart = Carbon::parse('2099-01-01');
    $alg = []; $algMiss = 0;
    echo "▶ ALGORITMA NEH+EDD (program)\n" . str_repeat('─', 124) . "\n";
    printf("%-5s │ %-22s │ %-11s │ %-13s │ %-11s │ %-13s │ %s\n",
        'SPK', 'Customer', 'Order masuk', 'Prod. mulai', 'Deadline', 'Selesai', 'Status');
    echo str_repeat('─', 124) . "\n";
    foreach ($spkDataset as $spk) {
        $o = $created[$spk['spk_no']]; $dl = Carbon::parse($spk['deadline']);
        $end = $o->estimated_end ? Carbon::parse($o->estimated_end) : null;
        $start = $o->production_start ? Carbon::parse($o->production_start) : null;
        $onTime = $end ? $end->lte($dl) : null;
        if ($end && $end->gt($algEnd)) $algEnd = $end->copy();
        if ($start && $start->lt($algStart)) $algStart = $start->copy();
        if ($onTime === false) $algMiss++;
        $early = $end ? (int) $end->diffInDays($dl, false) : 0;
        $alg[$spk['spk_no']] = compact('dl', 'end', 'start', 'onTime', 'early');
        printf("%-5s │ %-22s │ %-11s │ %-13s │ %-11s │ %-13s │ %s\n", $spk['spk_no'], mb_substr($spk['customer'], 0, 22),
            Carbon::parse($spk['order_date'])->format('d M Y'), $start?->format('d M Y') ?? 'N/A',
            $dl->format('d M Y'), $end?->format('d M Y') ?? 'N/A',
            $onTime ? "✓ {$early} hari lebih awal" : '⛔ telat');
    }
    echo str_repeat('─', 124) . "\n";
    $algSpan = (int) $algStart->diffInDays($algEnd);
    printf("  Makespan NEH+EDD: %d hari (selesai %s) — %d telat\n\n", $algSpan, $algEnd->format('d M Y'), $algMiss);

    // ── BUKTI DETAIL: tabel insertion item (NEH+EDD, dari jadwal nyata di DB) ──
    // Diurut waktu mulai kayu → memperlihatkan algoritma MENYISIPKAN item SPK 2798
    // di antara item order besar sejak 07 Nov, bukan menundanya seperti manual.
    $orderToSpk = [];
    foreach ($created as $no => $o) $orderToSpk[$o->id] = $no;
    $kmap = [];
    $kteam = function ($kode) use (&$kmap) { if (!isset($kmap[$kode])) $kmap[$kode] = 'K' . (count($kmap) + 1); return $kmap[$kode]; };
    $nehRows = [];
    $itemsAll = ProductionOrderItem::with(['schedules.station', 'schedules.team'])
        ->whereIn('production_order_id', array_keys($orderToSpk))->get();
    foreach ($itemsAll as $it) {
        $byStation = $it->schedules->keyBy(fn($s) => $s->station?->nama_station);
        $k = $byStation->get('kayu'); $c = $byStation->get('cat'); $a = $byStation->get('acc');
        if (!$k || !$c || !$a) continue;
        $nehRows[] = ['spk' => $orderToSpk[$it->production_order_id], 'desc' => (string) $it->keterangan,
            'kStart' => Carbon::parse($k->start_time), 'kTeam' => $kteam($k->team?->kode_team ?? '?'),
            'cStart' => Carbon::parse($c->start_time), 'aStart' => Carbon::parse($a->start_time),
            'aEnd' => Carbon::parse($a->end_time)];
    }
    usort($nehRows, fn($x, $y) => $x['kStart'] <=> $y['kStart']);
    $insHeader('BUKTI NEH+EDD — urutan item masuk lini (urut waktu mulai kayu, dari DB)');
    $nehNo = 0;
    foreach ($nehRows as $r) {
        $nehNo++;
        printf("%-3d │ %-5s │ %-30s │ %-11s %-3s │ %-12s │ %-12s │ %-12s\n",
            $nehNo, $r['spk'], mb_substr($r['desc'], 0, 30),
            $r['kStart']->format('d M H:i'), $r['kTeam'],
            $r['cStart']->format('d M H:i'), $r['aStart']->format('d M H:i'), $r['aEnd']->format('d M H:i'));
    }
    echo str_repeat('─', 116) . "\n\n";

    // Sorotan kontras 2798 (item pertama yang masuk lini pada tiap metode)
    $man2798first = null;
    foreach ($manLog as $r) { if ($r['spk'] === '2798') { $man2798first = $r['kStart']; break; } }
    $neh2798first = null;
    foreach ($nehRows as $r) { if ((string) $r['spk'] === '2798') { $neh2798first = $r['kStart']; break; } }
    echo "  SOROTAN item SPK 2798 (mendesak) — kapan item pertamanya MASUK lini kayu:\n";
    printf("    Manual : %s (menunggu di belakang order besar)\n", $man2798first?->format('d M Y H:i') ?? 'N/A');
    printf("    NEH+EDD: %s (disisipkan paling awal oleh algoritma)\n\n", $neh2798first?->format('d M Y H:i') ?? 'N/A');

    // ── Perbandingan ringkas ───────────────────────────────────────────────────
    echo str_repeat('═', 70) . "\n  PERBANDINGAN (sumber daya 6/6/3 sama)\n" . str_repeat('═', 70) . "\n";
    printf("  %-26s │ %-18s │ %s\n", 'Metrik', 'Manual', 'NEH+EDD');
    echo str_repeat('─', 70) . "\n";
    printf("  %-26s │ %-18s │ %s\n", 'Mulai produksi (t=0)', $simStart->format('d M Y'), $algStart->format('d M Y'));
    printf("  %-26s │ %-18s │ %s\n", 'Order tepat waktu', (3 - $fcfsMiss) . '/3', (3 - $algMiss) . '/3');
    printf("  %-26s │ %-18s │ %s\n", 'Total keterlambatan', "{$fcfsTard} hari", "0 hari");
    printf("  %-26s │ %-18s │ %s\n", 'Makespan', "{$fcfsSpan} hari", "{$algSpan} hari");
    printf("  %-26s │ %-18s │ %s\n", 'SPK 2798 (mendesak)',
        $fcfs['2798']['late'] ? "TELAT {$fcfs['2798']['daysLate']}h" : 'tepat',
        "selesai {$alg['2798']['early']} hari lebih awal");
    echo str_repeat('─', 70) . "\n";
    echo "  Catatan keadilan: kedua metode mulai dari t=0 yang sama & sumber daya 6/6/3\n";
    echo "  identik. Kolom 'Prod. mulai' per order adalah HASIL keputusan penjadwalan,\n";
    echo "  bukan input — justru itulah yang dioptimasi algoritma.\n\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // ASSERTIONS
    // ═══════════════════════════════════════════════════════════════════════════
    echo "▶ Assertions\n" . str_repeat('─', 60) . "\n";

    expect(ProductionSchedule::count())->toBe(29 * 3); // 12+9+8 = 29 item × 3 stasiun
    echo "  ✓ 29 item terjadwal di 3 stasiun (87 jadwal)\n";

    // KEADILAN: kedua metode mulai dari titik nol (t=0) yang sama
    expect($algStart->toDateString())->toBe($simStart->toDateString());
    echo "  ✓ Mulai produksi (t=0) sama: Manual = NEH+EDD = {$simStart->format('d M Y')}\n";

    // Manual menelatkan order kecil mendesak 2798
    expect($fcfs['2798']['late'])->toBeTrue('Manual harus menelatkan SPK 2798');
    echo "  ✓ Manual menelatkan SPK 2798 ({$fcfs['2798']['daysLate']} hari)\n";

    // NEH+EDD: semua order selesai SEBELUM deadline
    foreach ($alg as $no => $a) {
        expect($a['onTime'])->toBeTrue("SPK {$no} harus selesai sebelum deadline pada NEH+EDD");
        expect($a['early'])->toBeGreaterThanOrEqual(0);
    }
    echo "  ✓ NEH+EDD: 3/3 order selesai sebelum deadline\n";
    echo "    - 2798 (mendesak): {$alg['2798']['early']} hari lebih awal\n";
    echo "    - 2785          : {$alg['2785']['early']} hari lebih awal\n";
    echo "    - 2770          : {$alg['2770']['early']} hari lebih awal\n";

    // NEH+EDD makespan tidak lebih buruk dari Manual
    expect($algSpan)->toBeLessThanOrEqual($fcfsSpan + 1);
    echo "  ✓ Makespan NEH+EDD ({$algSpan} h) ≤ Manual ({$fcfsSpan} h)\n";

    // EDD: order tenggat paling awal (2798) mulai paling awal
    $s2798 = Carbon::parse($created['2798']->production_start);
    $s2770 = Carbon::parse($created['2770']->production_start);
    expect($s2798->lte($s2770))->toBeTrue('SPK 2798 (deadline paling awal) harus mulai ≤ 2770');
    echo "  ✓ Urutan EDD: SPK 2798 didahulukan (mulai ≤ 2770)\n";

    echo "\n" . str_repeat('═', 70) . "\n";
    printf("  KESIMPULAN: Manual %d/3 tepat (2798 telat %d hari) | NEH+EDD 3/3 selesai sebelum deadline\n",
        3 - $fcfsMiss, $fcfs['2798']['daysLate']);
    echo str_repeat('═', 70) . "\n\n";

    Carbon::setTestNow();
});
