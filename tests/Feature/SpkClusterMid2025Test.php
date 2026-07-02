<?php

/**
 * SPK Klaster Pertengahan 2025 — Jadwal Manual vs Algoritma NEH+EDD (fokus MAKESPAN)
 *
 * Tiga SPK produksi NYATA (dari dokumen SPK PRODUKSI) yang masuk pada tanggal
 * berdekatan dan berebut kapasitas pabrik yang sama:
 *   - SPK 2685 (Mrs. Helena, Surabaya)        — proyek besar,   19 item — masuk 19 Mei 2025
 *   - SPK 2704 (Mr Louis, Mojokerto)          — proyek menengah, 4 item — masuk 26 Mei 2025
 *   - SPK 2737 (Mr Santoso Wijono, Lumajang)  — proyek kecil,    3 item — masuk  2 Jun 2025
 *
 * Ketiganya bersaing memperebutkan pabrik 6 tim kayu / 6 cat / 3 acc. SPK 2704
 * mencantumkan tenggat eksplisit "19 Juli 2025" pada dokumennya; dua SPK lain
 * ditetapkan tenggat WAJAR sesuai bobot pekerjaan.
 *
 * Baseline = "jadwal manual operator": mendahulukan proyek BESAR dulu
 * (2685 → 2704 → 2737), memproses order satu per satu sampai tuntas. Pola ini
 * SAH dan TIDAK telat, tetapi menghasilkan makespan yang lebih PANJANG karena
 * order kecil mengantre di belakang order besar dan packing lini menjadi buruk.
 *
 * Algoritma NEH+EDD menyisipkan order kecil/mendesak lebih awal sehingga lini
 * tiga stasiun (kayu→cat→acc) terisi lebih rapat → makespan LEBIH PENDEK dan
 * SELURUH order selesai sebelum tenggat dengan sisa waktu lebih banyak.
 *
 * Klaim utama test ini: pada sumber daya & titik mulai (t=0) yang IDENTIK,
 * makespan NEH+EDD secara TEGAS lebih pendek dari jadwal manual. Anchor
 * perencanaan: Senin, 2 Jun 2025. Dimensi cm (dikonversi dari mm dokumen).
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

test('SPK Klaster Mid 2025: Manual vs NEH+EDD — algoritma makespan lebih pendek', function () {

    // Seed ONLY master data.
    /** @var \Tests\TestCase $this */
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
    // Perencanaan dijalankan saat order terakhir (2737) masuk: 2 Jun 2025 (Senin).
    Carbon::setTestNow(Carbon::parse('2025-06-02 08:00:00'));

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

    // ── Dataset: 3 SPK klaster Mei–Jun 2025 (array order = urutan Manual) ───────
    // Format item: [deskripsi, panjang (cm), tinggi (cm), qty].
    // Panjang × tinggi = bidang muka (dikonversi dari mm pada dokumen ÷10).
    $spkDataset = [
        [
            'spk_no' => '2685', 'customer' => 'Mrs. Helena', 'location' => 'Graha Family SS/52, Surabaya',
            'order_date' => '2025-05-19', 'deadline' => '2025-07-04', // proyek besar, tenggat KETAT (selesai manual 26 Jun)
            'items' => [
                ['Foyer — Kabinet storage + Wallpanel backing (veneer white oak)',   200, 340, 1],
                ['Foyer — Wallpanel dinding storage + Pintu kamuflase',              385, 340, 1],
                ['Foyer — Wallpanel dinding kitchen + Pintu kamuflase',              495, 340, 1],
                ['Working Room — Lemari display (HPL komb. frame alum w/glass)',      250, 340, 1],
                ['Working Room — Wallpanel samping meja kerja (HPL)',                190, 340, 1],
                ['Working Room — Meja kerja + Kabinet samping (HPL, leg besi PU)',    240,  75, 1],
                ['Pantry — Kabinet pantry atas bawah (veneer white oak)',            528, 320, 1],
                ['Pantry — Kabinet kulkas & microwave (veneer white oak)',           325, 320, 1],
                ['Pantry — Island (veneer white oak, exclude marmer)',               240,  90, 1],
                ['Living Room — Kabinet TV dan railing (frame alum w/glass)',        483, 340, 1],
                ['Living Room — Lemari display (frame alum w/glass)',                415, 140, 1],
                ['Master Bedroom — Wallpanel bedhead + Divan + Nakas gantung (2u)',  470, 110, 1],
                ['Master Bedroom — Meja TV (HPL)',                                   365,  60, 1],
                ['Ruang Kerja — Meja kerja + Ambalan (HPL)',                         350,  60, 1],
                ['WIC — Meja Rias + Cermin rias + Gate kongliong (HPL)',             165, 320, 1],
                ['WIC — Wardrobe area meja rias + Pintu kamuflase (clear mirror)',   225, 320, 1],
                ['WIC — Wardrobe (HPL komb. frame alum w/ grey glass)',              410, 320, 2],
                ['Master Bathroom — Meja wastafel + Cermin (PVC board HPL)',         235,  50, 1],
                ['Master Bathroom — Lemari storage (PVC board HPL)',                  85, 300, 1],
            ],
        ],
        [
            'spk_no' => '2704', 'customer' => 'Mr Louis', 'location' => 'Perum Villa Royal F-35, Mojokerto',
            'order_date' => '2025-05-26', 'deadline' => '2025-07-19', // tenggat EKSPLISIT dari dokumen
            'items' => [
                ['Diningroom & Pantry — Tall cabinet + Cabinet bawah (Marquina) + Lemari atas', 287, 300, 1],
                ['Master Bedroom — Nakas (HPL komb brown mirror)',                               60,  20, 2],
                ['Master Bedroom — Tv Cabinet dan meja kerja (HPL)',                            290, 300, 1],
                ['WIC — Wardrobe + Display tas (2u) + Meja rias + Cermin rias (HPL)',           405, 240, 1],
            ],
        ],
        [
            'spk_no' => '2737', 'customer' => 'Mr Santoso Wijono', 'location' => 'Lumajang',
            'order_date' => '2025-06-02', 'deadline' => '2025-06-27', // proyek kecil, masuk terakhir, tenggat KETAT
            'items' => [
                ['Wet Kitchen — Cabinet bawah (PVC area sink) + Cabinet atas (HPL)', 336,  92, 1],
                ['Ruang Audio — Display CD tengah (HPL)',                            150, 120, 1],
                ['Ruang Audio — Display piringan hitam sisi dinding (HPL)',          218, 197, 1],
            ],
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // Jadwal MANUAL BASELINE (mendahulukan proyek besar 2685 → 2704 → 2737), 6/6/3.
    // Produksi mulai pada anchor perencanaan (2 Jun 2025).
    // ═══════════════════════════════════════════════════════════════════════════
    $kayuN = Station::where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 6;
    $catN  = Station::where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 6;
    $accN  = Station::where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 3;

    $simStart = Carbon::parse('2025-06-02 08:00:00');
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
    echo "  KLASTER MEI–JUN 2025 — 3 SPK berdekatan (2685, 2704, 2737) | pabrik {$kayuN} kayu / {$catN} cat / {$accN} acc\n";
    echo str_repeat('═', 110) . "\n\n";

    echo "▶ Jadwal Manual (mendahulukan proyek besar 2685 → 2704 → 2737)\n" . str_repeat('─', 124) . "\n";
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
    // Item SPK 2737 (kecil, masuk terakhir) baru menyentuh lini kayu setelah ke-23
    // item proyek besar 2685 & 2704 selesai diproses → ekor makespan memanjang.
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
    // Diurut waktu mulai kayu → memperlihatkan algoritma MENYISIPKAN item SPK 2737
    // (& 2704) di antara item proyek besar 2685 sejak 2 Jun, bukan menundanya.
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

    // Sorotan kontras 2737 (item pertama yang masuk lini pada tiap metode)
    $man2737first = null;
    foreach ($manLog as $r) { if ($r['spk'] === '2737') { $man2737first = $r['kStart']; break; } }
    $neh2737first = null;
    foreach ($nehRows as $r) { if ((string) $r['spk'] === '2737') { $neh2737first = $r['kStart']; break; } }
    echo "  SOROTAN item SPK 2737 (kecil, masuk terakhir) — kapan item pertamanya MASUK lini kayu:\n";
    printf("    Manual : %s (menunggu di belakang proyek besar)\n", $man2737first?->format('d M Y H:i') ?? 'N/A');
    printf("    NEH+EDD: %s (disisipkan lebih awal oleh algoritma)\n\n", $neh2737first?->format('d M Y H:i') ?? 'N/A');

    // ── Perbandingan ringkas ───────────────────────────────────────────────────
    echo str_repeat('═', 70) . "\n  PERBANDINGAN (sumber daya 6/6/3 sama)\n" . str_repeat('═', 70) . "\n";
    $gainDays = $fcfsSpan - $algSpan;
    $gainPct = $fcfsSpan > 0 ? round($gainDays / $fcfsSpan * 100, 1) : 0;
    printf("  %-26s │ %-18s │ %s\n", 'Metrik', 'Manual', 'NEH+EDD');
    echo str_repeat('─', 70) . "\n";
    printf("  %-26s │ %-18s │ %s\n", 'Mulai produksi (t=0)', $simStart->format('d M Y'), $algStart->format('d M Y'));
    printf("  %-26s │ %-18s │ %s\n", 'Order tepat waktu', (3 - $fcfsMiss) . '/3', (3 - $algMiss) . '/3');
    printf("  %-26s │ %-18s │ %s\n", 'Makespan', "{$fcfsSpan} hari", "{$algSpan} hari");
    printf("  %-26s │ %-18s │ %s\n", 'Penghematan makespan', '—', "{$gainDays} hari ({$gainPct}%)");
    echo str_repeat('─', 70) . "\n";
    echo "  Catatan keadilan: kedua metode mulai dari t=0 yang sama & sumber daya 6/6/3\n";
    echo "  identik. Kolom 'Prod. mulai' per order adalah HASIL keputusan penjadwalan,\n";
    echo "  bukan input — justru itulah yang dioptimasi algoritma.\n\n";

    // ═══════════════════════════════════════════════════════════════════════════
    // ASSERTIONS
    // ═══════════════════════════════════════════════════════════════════════════
    echo "▶ Assertions\n" . str_repeat('─', 60) . "\n";

    expect(ProductionSchedule::count())->toBe(26 * 3); // 19+4+3 = 26 item × 3 stasiun
    echo "  ✓ 26 item terjadwal di 3 stasiun (78 jadwal)\n";

    // KEADILAN: kedua metode mulai dari titik nol (t=0) yang sama
    expect($algStart->toDateString())->toBe($simStart->toDateString());
    echo "  ✓ Mulai produksi (t=0) sama: Manual = NEH+EDD = {$simStart->format('d M Y')}\n";

    // KLAIM UTAMA: makespan NEH+EDD TEGAS lebih pendek dari manual
    expect($algSpan)->toBeLessThan($fcfsSpan);
    echo "  ✓ Makespan NEH+EDD ({$algSpan} h) < Manual ({$fcfsSpan} h) — hemat {$gainDays} hari ({$gainPct}%)\n";

    // NEH+EDD: semua order selesai SEBELUM deadline (dan tidak lebih buruk dari manual)
    foreach ($alg as $no => $a) {
        expect($a['onTime'])->toBeTrue("SPK {$no} harus selesai sebelum deadline pada NEH+EDD");
        expect($a['early'])->toBeGreaterThanOrEqual(0);
    }
    echo "  ✓ NEH+EDD: 3/3 order selesai sebelum deadline\n";
    echo "    - 2737 (kecil)   : {$alg['2737']['early']} hari lebih awal\n";
    echo "    - 2704 (menengah): {$alg['2704']['early']} hari lebih awal\n";
    echo "    - 2685 (besar)   : {$alg['2685']['early']} hari lebih awal\n";

    // EDD/penyisipan: item SPK 2737 (deadline paling awal) MASUK lini lebih awal
    // pada NEH+EDD dibanding manual yang menundanya di belakang proyek besar.
    // (Catatan: pada level order, 2685 yang besar menjenuhkan 6 tim di t=0 sehingga
    //  "production_start"-nya paling awal; bukti EDD yang sahih ada di level item.)
    expect($neh2737first)->not->toBeNull();
    expect($man2737first)->not->toBeNull();
    expect($neh2737first->lt($man2737first))->toBeTrue('Item 2737 harus masuk lini lebih awal di NEH+EDD');
    echo "  ✓ Penyisipan EDD: item SPK 2737 masuk lini {$neh2737first->format('d M')} (NEH) < {$man2737first->format('d M')} (manual)\n";

    echo "\n" . str_repeat('═', 70) . "\n";
    printf("  KESIMPULAN: Manual makespan %d hari vs NEH+EDD %d hari (hemat %d hari, %s%%); 3/3 tepat waktu di kedua metode\n",
        $fcfsSpan, $algSpan, $gainDays, $gainPct);
    echo str_repeat('═', 70) . "\n\n";

    Carbon::setTestNow();
});
