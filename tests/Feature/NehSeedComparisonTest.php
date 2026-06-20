<?php

/**
 * NEH SEED COMPARISON — deadline adherence vs makespan
 * ─────────────────────────────────────────────────────────────────────────────
 * Membandingkan 3 strategi pengurutan pada partisi `normal` (tempat NEH jalan),
 * memakai mesin objektif ASLI program (simulateMakespan: makespan*1.4 +
 * tardiness*2.0) untuk insertion NEH:
 *
 *   A. EDD-ONLY  : urut Earliest Due Date saja, tanpa NEH   (algoritma LAMA on_going)
 *   B. NEH-LPT   : NEH di-seed Longest-Processing-Time      (edit unifikasi sekarang)
 *   C. NEH-EDD   : NEH di-seed Earliest-Due-Date            (usulan perbaikan)
 *
 * Diukur PER-ORDER: berapa order telat (miss), total tardiness, dan makespan.
 * Tujuannya membuktikan/membantah: apakah seed EDD mengurangi miss deadline
 * dibanding seed LPT, tanpa memperburuk makespan secara berarti.
 */

use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('NEH seed: EDD-only vs NEH-LPT vs NEH-EDD (deadline miss & makespan)', function () {

    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00')); // Monday

    config([
        'production.minutes_per_m2'          => 300,
        'production.base_production_minutes'  => 1440,
        'production.cm2_per_m2'              => 10000,
        'production.work_minutes_per_day'    => 540,
        'production.station_split'           => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2],
        'production.calendar_penalty'        => 1.4,
        'production.tardiness_weight'        => 2.0,
        'production.handoff_buffer_minutes'  => 0.0,
    ]);

    $svc = app(SchedulingService::class);
    $sim = new ReflectionMethod($svc, 'simulateMakespan');
    $sim->setAccessible(true);
    $teams   = ['kayu_teams' => 6, 'cat_teams' => 6, 'acc_teams' => 3];
    $penalty = 1.4;

    // Program objective (makespan*1.4 + tardiness*2.0) — used by NEH insertion.
    $score = fn(array $seq): float => (float) $sim->invoke($svc, $seq, $teams);

    // Build a job (one item) with backward-scheduled item deadline, carrying its
    // ORDER deadline + spk so we can measure per-order lateness afterwards.
    $mkJob = function (string $spk, string $desc, float $p, float $h, int $q, int $n, string $orderDeadline): array {
        $t = (1440.0 / max(1, $n)) + (($p * $h) / 10000.0) * $q * 300.0;
        $itemDeadline = Carbon::parse($orderDeadline)->copy()->subMinutes((int) $t)->subDays(2);
        $stub = new class($itemDeadline) {
            public $production_deadline;
            public $productionOrder;
            public function __construct($d) { $this->production_deadline = $d; $this->productionOrder = (object) ['production_deadline' => $d]; }
        };
        return [
            'spk' => $spk, 'desc' => $desc,
            'p_kayu' => $t * 0.4, 'p_cat' => $t * 0.4, 'p_acc' => $t * 0.2, 't_total' => $t,
            'deadline'      => $itemDeadline,                 // item-level (for EDD/NEH)
            'orderDeadline' => Carbon::parse($orderDeadline), // order-level (for miss metric)
            'item' => $stub,
        ];
    };

    // Greedy multi-team simulation → makespan + per-job acc completion (working min).
    $simulate = function (array $seq) use ($teams) {
        $K = array_fill(0, $teams['kayu_teams'], 0.0);
        $C = array_fill(0, $teams['cat_teams'], 0.0);
        $A = array_fill(0, $teams['acc_teams'], 0.0);
        $ms = 0.0; $comp = [];
        foreach ($seq as $j) {
            $ki = array_search(min($K), $K); $ek = $K[$ki] + $j['p_kayu']; $K[$ki] = $ek;
            $ci = array_search(min($C), $C); $ec = max($C[$ci], $ek) + $j['p_cat']; $C[$ci] = $ec;
            $ai = array_search(min($A), $A); $ea = max($A[$ai], $ec) + $j['p_acc']; $A[$ai] = $ea;
            $comp[] = ['spk' => $j['spk'], 'end' => $ea, 'orderDeadline' => $j['orderDeadline']];
            if ($ea > $ms) $ms = $ea;
        }
        return ['makespan' => $ms, 'comp' => $comp];
    };

    // Per-ORDER lateness: order completes at its latest item; compare end*1.4 vs
    // (now → order deadline) in minutes — the SAME definition simulateMakespan uses.
    // Also tracks MIN SLACK (tightest order's buffer) — the de-risking metric that
    // matters even when nothing misses (the real 2798-vs-2770 situation).
    $evalOrders = function (array $r) use ($penalty) {
        $byOrder = [];
        foreach ($r['comp'] as $c) {
            $byOrder[$c['spk']]['end'] = max($byOrder[$c['spk']]['end'] ?? 0, $c['end']);
            $byOrder[$c['spk']]['dl']  = $c['orderDeadline'];
        }
        $miss = 0; $tard = 0.0; $minSlack = PHP_INT_MAX;
        foreach ($byOrder as $o) {
            $deadlineMinutes = Carbon::now()->diffInMinutes($o['dl'], false);
            $slack = $deadlineMinutes - ($o['end'] * $penalty);
            if ($slack < $minSlack) $minSlack = $slack;
            $t = max(0.0, -$slack);
            if ($t > 0) $miss++;
            $tard += $t;
        }
        return ['miss' => $miss, 'tard' => $tard, 'orders' => count($byOrder), 'minSlack' => $minSlack];
    };

    // NEH with a chosen seed; insertion uses the program objective ($score).
    $neh = function (array $jobs, callable $seedCmp) use ($score): array {
        usort($jobs, $seedCmp);
        if (count($jobs) <= 1) return $jobs;
        $seq = [$jobs[0]];
        for ($i = 1; $i < count($jobs); $i++) {
            $best = PHP_INT_MAX; $bestSeq = [];
            for ($pos = 0; $pos <= count($seq); $pos++) {
                $t = $seq; array_splice($t, $pos, 0, [$jobs[$i]]);
                $s = $score($t);
                if ($s < $best) { $best = $s; $bestSeq = $t; }
            }
            $seq = $bestSeq;
        }
        return $seq;
    };
    $cmpEdd = fn($a, $b) => $a['deadline']->timestamp <=> $b['deadline']->timestamp;
    $cmpLpt = fn($a, $b) => $b['t_total'] <=> $a['t_total'];

    // Program's REAL partition at a given critical window: items whose deadline is
    // within W days → CRITICAL (EDD, protected); the rest → NORMAL (NEH, LPT seed).
    $scheduleWindow = function (array $jobs, int $window) use ($neh, $cmpEdd, $cmpLpt) {
        $now = Carbon::now();
        $crit = array_values(array_filter($jobs, fn($j) => $now->diffInDays($j['deadline'], false) <= $window));
        $norm = array_values(array_filter($jobs, fn($j) => $now->diffInDays($j['deadline'], false) > $window));
        usort($crit, $cmpEdd);
        return array_merge($crit, count($norm) > 1 ? $neh($norm, $cmpLpt) : $norm);
    };

    // ── Test cases ─────────────────────────────────────────────────────────────
    // Each: name + list of orders [spk, order-deadline, items[[desc,p,h,qty],...]].
    // Scenarios stress capacity (more orders than teams) so ORDERING decides who
    // is late. Deadlines tight for SMALL orders, loose for BIG ones — the exact
    // tension that demoted 2798 behind 2770.
    $cases = [
        // 2 tight-small vs 3 loose-big — measure the tightest order's slack.
        'C1 tight-small vs loose-big (kasus 2798 vs 2770)' => [
            ['T', '2026-01-13', [['kecil', 120, 75, 1], ['kecil2', 100, 80, 1]]],
            ['L', '2026-02-28', [['besar1', 420, 360, 2], ['besar2', 400, 340, 2], ['besar3', 380, 320, 2]]],
        ],
        // 12 single-item orders: 6 tight-small + 6 loose-big, > 6 kayu teams → queueing.
        'C2 kontensi 12 order (6 ketat-kecil + 6 longgar-besar)' => [
            ['s1', '2026-01-14', [['a', 140, 110, 1]]],
            ['s2', '2026-01-14', [['b', 150, 120, 1]]],
            ['s3', '2026-01-15', [['c', 130, 100, 1]]],
            ['s4', '2026-01-15', [['d', 145, 115, 1]]],
            ['s5', '2026-01-16', [['e', 135, 105, 1]]],
            ['s6', '2026-01-16', [['f', 150, 120, 1]]],
            ['B1', '2026-03-01', [['g', 430, 360, 2]]],
            ['B2', '2026-03-01', [['h', 420, 350, 2]]],
            ['B3', '2026-03-05', [['i', 440, 370, 2]]],
            ['B4', '2026-03-05', [['j', 410, 340, 2]]],
            ['B5', '2026-03-10', [['k', 430, 360, 2]]],
            ['B6', '2026-03-10', [['l', 400, 330, 2]]],
        ],
        // All tight, graduated deadlines — capacity exceeded, who misses depends on order.
        'C3 kontensi tinggi (10 order, deadline bertingkat)' => [
            ['H1', '2026-01-12', [['x1', 300, 260, 2]]],
            ['H2', '2026-01-13', [['x2', 280, 240, 2]]],
            ['H3', '2026-01-13', [['x3', 320, 280, 2]]],
            ['H4', '2026-01-14', [['x4', 260, 220, 2]]],
            ['H5', '2026-01-14', [['x5', 340, 300, 2]]],
            ['H6', '2026-01-15', [['x6', 300, 260, 2]]],
            ['H7', '2026-01-15', [['x7', 310, 270, 2]]],
            ['H8', '2026-01-16', [['x8', 290, 250, 2]]],
            ['H9', '2026-01-16', [['x9', 330, 290, 2]]],
            ['H10', '2026-01-17', [['x10', 300, 260, 2]]],
        ],
        // Aligned (biggest order also earliest deadline) — sanity: no regression.
        'C4 selaras (order terbesar deadline tercepat)' => [
            ['B', '2026-01-22', [['big', 400, 340, 2], ['big2', 360, 300, 2]]],
            ['M', '2026-02-05', [['mid', 220, 180, 1]]],
            ['S', '2026-02-25', [['small', 100, 80, 1]]],
        ],
    ];

    $agg = [
        'EDD'    => ['miss' => 0, 'tard' => 0.0],
        'LPT'    => ['miss' => 0, 'tard' => 0.0],
        'NEHEDD' => ['miss' => 0, 'tard' => 0.0],
        'HI'     => ['miss' => 0, 'tard' => 0.0],
        'WIN14'  => ['miss' => 0, 'tard' => 0.0],
    ];

    echo "\n" . str_repeat('═', 92) . "\n";
    echo "  PERBANDINGAN SEED NEH — miss deadline (per-order), tardiness, makespan (6/6/3 tim)\n";
    echo str_repeat('═', 92) . "\n";

    foreach ($cases as $name => $orders) {
        $jobs = [];
        foreach ($orders as $o) {
            $n = count($o[2]);
            foreach ($o[2] as $it) {
                $jobs[] = $mkJob($o[0], "{$o[0]}:{$it[0]}", (float) $it[1], (float) $it[2], (int) $it[3], $n, $o[1]);
            }
        }

        // A. EDD-only (no NEH) — the original on_going behaviour.
        $seqEdd = $jobs; usort($seqEdd, $cmpEdd);
        // B. NEH seeded LPT (current edit) and C. NEH seeded EDD — both under weight 2.
        config(['production.tardiness_weight' => 2.0]);
        $seqLpt = $neh($jobs, $cmpLpt);
        $seqNed = $neh($jobs, $cmpEdd);
        // D. NEH seeded EDD but with a HEAVY tardiness weight, so NEH refuses to
        //    trade a deadline miss for a shorter makespan.
        config(['production.tardiness_weight' => 25.0]);
        $seqHi = $neh($jobs, $cmpEdd);
        config(['production.tardiness_weight' => 2.0]);
        // E. Program partition, 14-day critical window: critical=EDD ++ normal=NEH-LPT.
        $seqW = $scheduleWindow($jobs, 14);

        $rEdd = $simulate($seqEdd); $eEdd = $evalOrders($rEdd);
        $rLpt = $simulate($seqLpt); $eLpt = $evalOrders($rLpt);
        $rNed = $simulate($seqNed); $eNed = $evalOrders($rNed);
        $rHi  = $simulate($seqHi);  $eHi  = $evalOrders($rHi);
        $rW   = $simulate($seqW);   $eW   = $evalOrders($rW);

        foreach (['EDD' => $eEdd, 'LPT' => $eLpt, 'NEHEDD' => $eNed, 'HI' => $eHi, 'WIN14' => $eW] as $k => $e) {
            $agg[$k]['miss'] += $e['miss']; $agg[$k]['tard'] += $e['tard'];
        }

        $d = fn($min) => $min / 540.0; // working minutes → working days
        echo "\n  " . $name . "  (" . $eEdd['orders'] . " order, " . count($jobs) . " item)\n";
        echo "  " . str_repeat('─', 90) . "\n";
        printf("  %-26s │ %5s │ %11s │ %11s │ %10s\n", 'Strategi', 'miss', 'tardiness', 'min-slack', 'makespan');
        printf("  %-26s │ %5d │ %7.1f hari │ %+7.1f hari │ %6.1f hari\n", 'A. EDD-only (lama)',       $eEdd['miss'], $d($eEdd['tard']), $d($eEdd['minSlack']), $d($rEdd['makespan']));
        printf("  %-26s │ %5d │ %7.1f hari │ %+7.1f hari │ %6.1f hari\n", 'B. NEH-LPT (sekarang)',     $eLpt['miss'], $d($eLpt['tard']), $d($eLpt['minSlack']), $d($rLpt['makespan']));
        printf("  %-26s │ %5d │ %7.1f hari │ %+7.1f hari │ %6.1f hari\n", 'C. NEH-EDD (seed saja)',    $eNed['miss'], $d($eNed['tard']), $d($eNed['minSlack']), $d($rNed['makespan']));
        printf("  %-26s │ %5d │ %7.1f hari │ %+7.1f hari │ %6.1f hari\n", 'D. NEH-EDD + tardiness×25', $eHi['miss'],  $d($eHi['tard']),  $d($eHi['minSlack']),  $d($rHi['makespan']));
        printf("  %-26s │ %5d │ %7.1f hari │ %+7.1f hari │ %6.1f hari\n", 'E. partisi window=14',     $eW['miss'],   $d($eW['tard']),   $d($eW['minSlack']),   $d($rW['makespan']));
    }

    echo "\n" . str_repeat('═', 92) . "\n";
    echo "  TOTAL (semua kasus)\n";
    echo str_repeat('─', 92) . "\n";
    printf("  %-26s │ miss=%d │ tardiness=%.0f menit\n", 'A. EDD-only (lama)',       $agg['EDD']['miss'],    $agg['EDD']['tard']);
    printf("  %-26s │ miss=%d │ tardiness=%.0f menit\n", 'B. NEH-LPT (sekarang)',     $agg['LPT']['miss'],    $agg['LPT']['tard']);
    printf("  %-26s │ miss=%d │ tardiness=%.0f menit\n", 'C. NEH-EDD (seed saja)',    $agg['NEHEDD']['miss'], $agg['NEHEDD']['tard']);
    printf("  %-26s │ miss=%d │ tardiness=%.0f menit\n", 'D. NEH-EDD + tardiness×25', $agg['HI']['miss'],     $agg['HI']['tard']);
    printf("  %-26s │ miss=%d │ tardiness=%.0f menit\n", 'E. partisi window=14',     $agg['WIN14']['miss'],  $agg['WIN14']['tard']);
    echo str_repeat('═', 92) . "\n\n";

    // Finding (documented): pure EDD never misses more than any NEH variant — it is
    // the deadline-safe baseline. The window=14 partition (E) should match EDD's
    // miss count while still letting NEH optimise the far-deadline orders.
    $minNehMiss = min($agg['LPT']['miss'], $agg['NEHEDD']['miss'], $agg['HI']['miss']);
    expect($agg['EDD']['miss'])->toBeLessThanOrEqual($minNehMiss);
    expect($agg['WIN14']['miss'])->toBeLessThanOrEqual($agg['LPT']['miss']);
});
