<?php

/**
 * HIBRIDA "EDD per order + NEH per item" (dengan batasan prioritas antar-order)
 * ─────────────────────────────────────────────────────────────────────────────
 * Pengujian ini menjawab pertanyaan desain: ketika beberapa order materialnya
 * sudah datang dan siap dieksekusi, dapatkah penjadwalan tetap "EDD + NEH" yaitu
 *
 *      (1) EDD menentukan URUTAN ANTAR-ORDER  → order ber-deadline lebih dekat
 *          dikerjakan lebih dulu; dan
 *      (2) NEH menentukan URUTAN ITEM DI DALAM tiap order → mengejar makespan
 *          terkecil untuk item-item milik order itu,
 *
 * dengan SYARAT KERAS: item milik order ke-2 TIDAK BOLEH mendahului item order
 * ke-1 (blok order tidak saling menyusup). Dengan begitu jaminan ketepatan
 * deadline (EDD) dipertahankan, sementara NEH dipakai untuk efisiensi internal.
 *
 * Skenario: dua order siap-eksekusi memperebutkan pabrik 6 tim kayu / 6 cat / 3 acc.
 *   - Order A (deadline lebih awal)  → EDD memberi prioritas pertama
 *   - Order B = SPK 2798 (8 item, deadline lebih longgar) → prioritas kedua
 *
 * Mesin makespan = SchedulingService::simulateMakespan() ASLI (dipanggil via
 * reflection). calendar_penalty=1.0 & tardiness_weight=0 agar nilai yang
 * dikembalikan adalah makespan mentah dalam menit kerja.
 *
 * Yang dibandingkan (urutan antar-order = EDD untuk semua, blok dijaga):
 *   1. EKSEKUSI SAAT INI (EDD per item)  — perilaku on_going sekarang
 *   2. HIBRIDA (EDD order + NEH item)     — usulan
 *   3. NEH GLOBAL tanpa batasan blok      — pembanding (boleh langgar prioritas)
 */

use App\Services\Production\SchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Hibrida EDD-per-order + NEH-per-item dengan batasan blok prioritas', function () {

    // Makespan mentah: penalty 1.0, tanpa bobot tardiness, tanpa handoff.
    config([
        'production.minutes_per_m2'         => 300,
        'production.base_production_minutes' => 1440,
        'production.cm2_per_m2'             => 10000,
        'production.work_minutes_per_day'   => 540,
        'production.station_split'          => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2],
        'production.calendar_penalty'       => 1.0,
        'production.tardiness_weight'       => 0.0,
        'production.handoff_buffer_minutes' => 0.0,
    ]);

    // ── Mesin makespan ASLI lewat reflection (6/6/3 tim, sama seperti program) ──
    $svc  = app(SchedulingService::class);
    $sim  = new ReflectionMethod($svc, 'simulateMakespan');
    $sim->setAccessible(true);
    $teams = ['kayu_teams' => 6, 'cat_teams' => 6, 'acc_teams' => 3];
    $makespan = fn(array $seq): float => (float) $sim->invoke($svc, $seq, $teams);

    // ── Pembuat job: pakai rumus calculateJobMinutes (split 0.4/0.4/0.2) ────────
    $mkJob = function (string $spk, string $desc, float $p, float $h, int $q, int $n): array {
        $t = (1440.0 / max(1, $n)) + (($p * $h) / 10000.0) * $q * 300.0;
        return [
            'spk' => $spk, 'desc' => $desc,
            'p_kayu' => $t * 0.4, 'p_cat' => $t * 0.4, 'p_acc' => $t * 0.2, 't_total' => $t,
        ];
    };

    // ── NEH per kumpulan item (pre-sort LPT, lalu insertion — identik runNeh) ───
    $neh = function (array $jobs) use ($makespan): array {
        usort($jobs, fn($a, $b) => $b['t_total'] <=> $a['t_total']);  // LPT pre-sort
        if (count($jobs) <= 1) return $jobs;
        $seq = [$jobs[0]];
        for ($i = 1; $i < count($jobs); $i++) {
            $best = PHP_INT_MAX; $bestSeq = [];
            for ($pos = 0; $pos <= count($seq); $pos++) {
                $t = $seq; array_splice($t, $pos, 0, [$jobs[$i]]);
                $m = $makespan($t);
                if ($m < $best) { $best = $m; $bestSeq = $t; }
            }
            $seq = $bestSeq;
        }
        return $seq;
    };

    // ── EDD per item (perilaku on_going saat ini): deadline item naik = LPT ─────
    // Di dalam SATU order, deadline item = deadline_order − proses − buffer, jadi
    // urutan EDD-per-item setara LPT (proses terbesar dulu). Tanpa insertion.
    $eddItem = function (array $jobs): array {
        usort($jobs, fn($a, $b) => $b['t_total'] <=> $a['t_total']);
        return $jobs;
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // Dataset: dua order SIAP-EKSEKUSI (material datang). Order A deadline lebih
    // awal → EDD prioritas-1. Order B = SPK 2798 (8 item) → prioritas-2.
    // ═══════════════════════════════════════════════════════════════════════════
    $orders = [
        [
            'spk' => '2770', 'deadline' => '2025-12-01',   // EDD: lebih awal → duluan
            'items' => [
                // desc, panjang, tinggi, qty
                ['A1 — Wardrobe besar',  300, 240, 1],
                ['A2 — Vanity cabinet',   90,  90, 2],
                ['A3 — Wallpanel',       420, 290, 1],
            ],
        ],
        [
            'spk' => '2798', 'deadline' => '2025-12-18',   // EDD: lebih longgar → kedua
            'items' => [
                ['B1 — Meja wastafel + Mirror (B1)',  100, 185, 1],
                ['B2 — Meja wastafel + Mirror (1F)',   90, 200, 1],
                ['B3 — Meja wastafel + Ambalan',       90, 135, 1],
                ['B4 — 2x Meja wastafel + Pedestal',  160,  85, 1],
                ['B5 — Meja wastafel + Mirror round',  90, 135, 1],
                ['B6 — Meja wastafel + Shelving',     185, 185, 1],
                ['B7 — Meja wastafel solid surface',  185,  50, 1],
                ['B8 — Pintu kamuflase + WIC',         90, 285, 2],
            ],
        ],
    ];

    // EDD antar-order: urutkan order berdasarkan deadline (naik).
    usort($orders, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));

    // Bangun job per order (n = jumlah item order tsb).
    $jobsByOrder = [];
    foreach ($orders as $o) {
        $n = count($o['items']);
        $jobsByOrder[$o['spk']] = array_map(
            fn($it) => $mkJob($o['spk'], $it[0], (float) $it[1], (float) $it[2], (int) $it[3], $n),
            $o['items']
        );
    }

    // ── Tabel waktu proses per item (bukti dasar) ──────────────────────────────
    echo "\n" . str_repeat('═', 92) . "\n";
    echo "  HIBRIDA EDD-per-order + NEH-per-item  |  pabrik 6 kayu / 6 cat / 3 acc\n";
    echo "  EDD antar-order: " . implode(' → ', array_map(fn($o) => "SPK {$o['spk']} (dl {$o['deadline']})", $orders)) . "\n";
    echo str_repeat('═', 92) . "\n\n";
    echo "▶ Waktu proses per item (menit kerja)\n" . str_repeat('─', 92) . "\n";
    printf("%-5s │ %-34s │ %8s │ %8s │ %8s │ %8s\n", 'SPK', 'Item', 'kayu', 'cat', 'acc', 'total');
    echo str_repeat('─', 92) . "\n";
    foreach ($orders as $o) {
        foreach ($jobsByOrder[$o['spk']] as $j) {
            printf("%-5s │ %-34s │ %8.0f │ %8.0f │ %8.0f │ %8.0f\n",
                $j['spk'], mb_substr($j['desc'], 0, 34), $j['p_kayu'], $j['p_cat'], $j['p_acc'], $j['t_total']);
        }
    }
    echo "\n";

    $toDays = fn(float $m) => $m / 540.0;
    $seqSpks = fn(array $s) => implode(' ', array_map(fn($j) => $j['spk'], $s));
    $seqDesc = fn(array $s) => array_map(fn($j) => "{$j['spk']}:" . trim(explode('—', $j['desc'])[0]), $s);

    // ── 1) EKSEKUSI SAAT INI: EDD per item, blok order dijaga (EDD antar-order) ──
    $seqBaseline = [];
    foreach ($orders as $o) {
        foreach ($eddItem($jobsByOrder[$o['spk']]) as $j) $seqBaseline[] = $j;
    }
    $mBaseline = $makespan($seqBaseline);

    // ── 2) HIBRIDA: EDD per order + NEH per item, blok order dijaga ─────────────
    $seqHybrid = [];
    foreach ($orders as $o) {
        foreach ($neh($jobsByOrder[$o['spk']]) as $j) $seqHybrid[] = $j;
    }
    $mHybrid = $makespan($seqHybrid);

    // ── 3) NEH GLOBAL tanpa batasan blok (boleh langgar prioritas order) ───────
    $allJobs = [];
    foreach ($orders as $o) foreach ($jobsByOrder[$o['spk']] as $j) $allJobs[] = $j;
    $seqGlobal  = $neh($allJobs);
    $mGlobal    = $makespan($seqGlobal);

    // ── Cek batasan blok: semua item order-1 mendahului semua item order-2 ──────
    $firstSpk = $orders[0]['spk'];
    $lastIdxOrder1 = -1; $firstIdxOrder2 = PHP_INT_MAX;
    foreach ($seqHybrid as $idx => $j) {
        if ($j['spk'] === $firstSpk) $lastIdxOrder1 = max($lastIdxOrder1, $idx);
        else $firstIdxOrder2 = min($firstIdxOrder2, $idx);
    }
    $blokTerjaga = $lastIdxOrder1 < $firstIdxOrder2;

    // Apakah NEH global melanggar prioritas (menaruh item order-2 sebelum order-1)?
    $globalFirstSpk = $seqGlobal[0]['spk'];
    $globalLanggar  = $globalFirstSpk !== $firstSpk
        || (function () use ($seqGlobal, $firstSpk) {
            $seenOther = false;
            foreach ($seqGlobal as $j) {
                if ($j['spk'] !== $firstSpk) $seenOther = true;
                elseif ($seenOther) return true; // item order-1 muncul setelah order-2
            }
            return false;
        })();

    // ── Hasil ──────────────────────────────────────────────────────────────────
    echo "▶ Perbandingan makespan (urutan antar-order = EDD)\n" . str_repeat('─', 92) . "\n";
    printf("%-46s │ %10s │ %9s │ %s\n", 'Strategi', 'makespan', '≈ hari', 'blok order');
    echo str_repeat('─', 92) . "\n";
    printf("%-46s │ %7.0f mnt │ %7.1f h │ %s\n", '1. Eksekusi saat ini (EDD per item)',   $mBaseline, $toDays($mBaseline), 'terjaga ✓');
    printf("%-46s │ %7.0f mnt │ %7.1f h │ %s\n", '2. HIBRIDA (EDD order + NEH item)',      $mHybrid,   $toDays($mHybrid),   $blokTerjaga ? 'terjaga ✓' : 'DILANGGAR ✗');
    printf("%-46s │ %7.0f mnt │ %7.1f h │ %s\n", '3. NEH global (tanpa batasan blok)',     $mGlobal,   $toDays($mGlobal),   $globalLanggar ? 'DILANGGAR ✗' : 'terjaga ✓');
    echo str_repeat('─', 92) . "\n";

    $gainHybrid = $mBaseline - $mHybrid;
    $gainGlobal = $mHybrid - $mGlobal;
    printf("\n  Δ Hibrida vs eksekusi-saat-ini : %+.0f menit (%+.1f hari) %s\n",
        -$gainHybrid, -$toDays($gainHybrid),
        $gainHybrid > 0.5 ? '→ NEH-per-item memperpendek makespan' : '→ praktis sama (lihat pembahasan)');
    printf("  Δ NEH-global vs Hibrida        : %+.0f menit (%+.1f hari) %s\n\n",
        -$gainGlobal, -$toDays($gainGlobal),
        $gainGlobal > 0.5 ? '→ biaya mempertahankan prioritas EDD antar-order' : '→ batasan blok tidak mahal');

    echo "  Urutan HIBRIDA : " . $seqSpks($seqHybrid) . "\n";
    echo "    " . implode(' | ', $seqDesc($seqHybrid)) . "\n";
    echo "  Urutan NEH-glob: " . $seqSpks($seqGlobal) . ($globalLanggar ? "   (item SPK $firstSpk tergeser oleh order lain)" : '') . "\n\n";

    // ── Assertions ─────────────────────────────────────────────────────────────
    // (a) EDD antar-order: blok order-1 utuh mendahului order-2 pada hibrida.
    expect($blokTerjaga)->toBeTrue();
    // (b) NEH per item tidak pernah memperburuk makespan vs eksekusi-saat-ini.
    expect($mHybrid)->toBeLessThanOrEqual($mBaseline + 1e-6);
    // (c) NEH global (tanpa batasan) adalah batas bawah: hibrida ≥ global.
    expect($mGlobal)->toBeLessThanOrEqual($mHybrid + 1e-6);
    // (d) Jumlah item terjaga di semua strategi.
    expect(count($seqBaseline))->toBe(count($allJobs));
    expect(count($seqHybrid))->toBe(count($allJobs));
    expect(count($seqGlobal))->toBe(count($allJobs));
});
