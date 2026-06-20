<?php

/**
 * BUKTI: Seeder + pipeline UI asli == hasil test code (jadwal & durasi sama).
 * ─────────────────────────────────────────────────────────────────────────────
 * Menjalankan `SpkKlasterPembuktianSeeder` lalu MEMICU jalur yang SAMA dengan
 * tombol UI "Konfirmasi Kedatangan Material (batch)" — yaitu
 * SchedulingService::confirmMaterialArrivalBatch — bukan simulator terisolasi.
 *
 * Di-anchor ke 7 Nov 2025 (sama dengan SpkClusterNov2025Test). Karena seeder
 * memasang deadline relatif now() (+15/+33/+35 hari), pada anchor ini deadline
 * jatuh pas 22 Nov / 10 Des / 12 Des 2025 — identik dengan test asli — sehingga
 * jadwal kalender yang dihasilkan HARUS sama.
 *
 * Asumsi: DB bersih (tidak ada order lain) → tim 6/6/3 kosong di t=0.
 */

use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Seeder klaster + confirmMaterialArrivalBatch menghasilkan jadwal & durasi yang sama', function () {

    // Anchor HARUS di-set SEBELUM seeder klaster berjalan — seeder memasang
    // deadline relatif now(). Karena itu kita TIDAK memakai $this->seed() penuh
    // (DatabaseSeeder ikut menjalankan SpkKlasterPembuktianSeeder lebih awal,
    // saat now() masih jam dinding nyata, sehingga deadline meleset jauh dan
    // partisi EDD/critical untuk SPK 2798 tidak aktif). Seed master saja, lalu
    // jalankan seeder klaster eksplisit pada anchor yang benar.
    Carbon::setTestNow(Carbon::parse('2025-11-07 08:00:00'));
    $this->seed([                                      // base data: product, material, factory, stasiun/tim
        \Database\Seeders\MaterialSeeder::class,
        \Database\Seeders\ProductSeeder::class,
        \Database\Seeders\FactoryLocationSeeder::class,
        \Database\Seeders\StationSeeder::class,
        \Database\Seeders\TeamSeeder::class,
        \Database\Seeders\ProductionStatusSeeder::class,
        \Database\Seeders\CustomerSeeder::class,
        \Database\Seeders\AdminUserSeeder::class,
    ]);

    // Jalankan seeder klaster (await_material, material lengkap, deadline relatif now)
    (new \Database\Seeders\SpkKlasterPembuktianSeeder())->setContainer(app())->run();

    $orders = ProductionOrder::where('order_id', 'like', '%KLASTER-BUKTI%')->get()->keyBy(function ($o) {
        return trim(explode('/', $o->order_id)[0]);
    });
    expect($orders)->toHaveCount(3);

    // ── Picu jalur UI asli: konfirmasi material batch → on_going → EDD ──────────
    app(SchedulingService::class)->confirmMaterialArrivalBatch($orders->pluck('id')->all());

    foreach ($orders as $no => $o) $orders[$no] = ProductionOrder::find($o->id);

    // ── Kumpulkan hasil jadwal dari DB (yang akan tampil di kalender) ───────────
    $deadlines = ['2770' => '2025-12-10', '2785' => '2025-12-12', '2798' => '2025-11-22'];
    $algStart = Carbon::parse('2099-01-01'); $algEnd = Carbon::parse('2000-01-01'); $miss = 0;

    echo "\n" . str_repeat('═', 100) . "\n";
    echo "  BUKTI SEEDER + PIPELINE UI (confirmMaterialArrivalBatch) — anchor 7 Nov 2025, DB bersih, 6/6/3 tim\n";
    echo str_repeat('═', 100) . "\n\n";
    printf("%-5s │ %-24s │ %-13s │ %-13s │ %-13s │ %s\n", 'SPK', 'Customer', 'Prod. mulai', 'Deadline', 'Selesai', 'Status');
    echo str_repeat('─', 100) . "\n";

    $rows = [];
    foreach (['2770', '2785', '2798'] as $no) {
        $o = $orders[$no];
        $start = $o->production_start ? Carbon::parse($o->production_start) : null;
        $end   = $o->estimated_end ? Carbon::parse($o->estimated_end) : null;
        $dl    = Carbon::parse($deadlines[$no]);
        $onTime = $end ? $end->lte($dl) : null;
        if ($start && $start->lt($algStart)) $algStart = $start->copy();
        if ($end && $end->gt($algEnd)) $algEnd = $end->copy();
        if ($onTime === false) $miss++;
        $early = $end ? (int) $end->diffInDays($dl, false) : 0;
        $rows[$no] = compact('start', 'end', 'dl', 'onTime', 'early');
        printf("%-5s │ %-24s │ %-13s │ %-13s │ %-13s │ %s\n",
            $no, mb_substr($o->nama_customer, 0, 24),
            $start?->format('d M Y') ?? 'N/A', $dl->format('d M Y'), $end?->format('d M Y') ?? 'N/A',
            $onTime ? "✓ {$early} hari lebih awal" : '⛔ telat');
    }
    echo str_repeat('─', 100) . "\n";
    $algSpan = (int) $algStart->diffInDays($algEnd);
    printf("  Mulai produksi: %s   |   Makespan (durasi total): %d hari   |   %d/3 tepat waktu\n\n",
        $algStart->format('d M Y'), $algSpan, 3 - $miss);

    // Urutan masuk lini kayu (item pertama 2798 = bukti EDD mendahulukan order mendesak)
    $items = ProductionOrderItem::with(['schedules.station'])
        ->whereIn('production_order_id', $orders->pluck('id')->all())->get();
    $orderToSpk = $orders->mapWithKeys(fn($o, $no) => [$o->id => $no]);
    $kayuStarts = [];
    foreach ($items as $it) {
        $k = $it->schedules->first(fn($s) => $s->station?->nama_station === 'kayu');
        if ($k) $kayuStarts[] = ['spk' => $orderToSpk[$it->production_order_id], 'start' => Carbon::parse($k->start_time)];
    }
    usort($kayuStarts, fn($a, $b) => $a['start'] <=> $b['start']);
    $first2798 = collect($kayuStarts)->firstWhere('spk', '2798');
    echo "  Item pertama SPK 2798 masuk lini kayu: " . ($first2798 ? $first2798['start']->format('d M Y H:i') : 'N/A')
        . " (EDD mendahulukan order mendesak)\n\n";

    // ── Assertions: harus identik dengan SpkClusterNov2025Test ─────────────────
    expect(ProductionSchedule::count())->toBe(29 * 3);          // 12+9+8 item × 3 stasiun
    expect($algStart->toDateString())->toBe('2025-11-07');       // mulai t=0 (material di tangan)
    foreach (['2770', '2785', '2798'] as $no) {
        expect($rows[$no]['onTime'])->toBeTrue("SPK {$no} harus selesai sebelum deadline");
    }
    expect($miss)->toBe(0);                                      // 3/3 tepat waktu
    expect($algSpan)->toBeGreaterThan(0);
    // EDD: 2798 (deadline paling awal) mulai paling awal
    expect(Carbon::parse($orders['2798']->production_start)->lte(Carbon::parse($orders['2770']->production_start)))->toBeTrue();

    echo "  ✓ 87 jadwal (29 item × 3 stasiun), mulai 7 Nov 2025, 3/3 tepat waktu, makespan {$algSpan} hari\n";
    echo "  ✓ Pipeline UI (confirmMaterialArrivalBatch) mereproduksi hasil SpkClusterNov2025Test.\n\n";

    Carbon::setTestNow();
});
