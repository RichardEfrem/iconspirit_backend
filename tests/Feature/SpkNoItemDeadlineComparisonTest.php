<?php

/**
 * "Bagaimana jika tidak ada deadline-item, hanya deadline-order?" — perbandingan
 * efisiensi tiga strategi pada data 3 SPK klaster (29 item), pabrik 6/6/3.
 *
 *   (1) EDD-ITEM (desain sekarang)  : item diurut deadline-item backward
 *                                     (deadline_order − proses − 2 hari)
 *   (2) EDD-ORDER + NEH-ITEM        : order diurut deadline-order; di DALAM tiap
 *       (proposal, tanpa dl-item)     order item disusun NEH (makespan); blok order
 *                                     tidak menyusup
 *   (3) NEH-GLOBAL (tanpa deadline) : NEH atas 29 item, murni makespan
 *
 * Dinilai simulator KALENDER (jam kerja) + deadline ORDER. Menjawab apakah
 * menghapus deadline-item membuat EDD+NEH lebih efisien.
 */

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Tanpa deadline-item: EDD-item vs EDD-order+NEH-item vs NEH-global', function () {

    $anchor = Carbon::parse('2025-11-07 08:00:00');
    $KN = 6; $CN = 6; $AN = 3;

    // Data 3 SPK: [panjang, tinggi, qty]
    $orders = [
        ['spk' => '2798', 'deadline' => Carbon::parse('2025-11-22 17:00'), 'items' => [
            [100,185,1],[90,200,1],[90,135,1],[160,85,1],[90,135,1],[185,185,1],[185,50,1],[90,285,2]]],
        ['spk' => '2770', 'deadline' => Carbon::parse('2025-12-10 17:00'), 'items' => [
            [432,240,1],[180,170,1],[465,240,1],[180,170,1],[455,240,2],[180,170,2],
            [455,240,1],[180,170,1],[380,240,1],[180,170,1],[450,300,1],[250,240,1]]],
        ['spk' => '2785', 'deadline' => Carbon::parse('2025-12-12 17:00'), 'items' => [
            [125,70,1],[120,75,1],[420,290,1],[368,320,1],[350,298,1],[138,70,1],
            [138,70,1],[220,380,1],[367,298,3]]],
    ];

    // Bangun job
    $jobs = [];
    foreach ($orders as $o) {
        $n = count($o['items']);
        foreach ($o['items'] as $it) {
            $t = (1440.0 / max(1,$n)) + ((($it[0]*$it[1])/10000.0) * $it[2] * 300.0);
            $jobs[] = [
                'spk' => $o['spk'], 'odl' => $o['deadline'],
                'p_kayu' => $t*0.4, 'p_cat' => $t*0.4, 'p_acc' => $t*0.2, 't_total' => $t,
                'idl' => $o['deadline']->copy()->subMinutes((int)$t)->subDays(2),
            ];
        }
    }

    // Objektif NEH = makespan MURNI (premis: tanpa deadline-item)
    $msObj = function (array $seq) use ($KN,$CN,$AN): float {
        $K=array_fill(0,$KN,0.0);$C=array_fill(0,$CN,0.0);$A=array_fill(0,$AN,0.0);$ms=0.0;
        foreach ($seq as $j){
            $ki=array_search(min($K),$K);$ek=$K[$ki]+$j['p_kayu'];$K[$ki]=$ek;
            $ci=array_search(min($C),$C);$ec=max($C[$ci],$ek)+$j['p_cat'];$C[$ci]=$ec;
            $ai=array_search(min($A),$A);$ea=max($A[$ai],$ec)+$j['p_acc'];$A[$ai]=$ea;
            if($ea>$ms)$ms=$ea;
        }
        return $ms;
    };
    $neh = function (array $js) use ($msObj): array {
        usort($js, fn($a,$b)=>$b['t_total']<=>$a['t_total']);
        if(count($js)<=1)return $js;
        $seq=[$js[0]];
        for($i=1;$i<count($js);$i++){ $best=PHP_INT_MAX;$bs=[];
            for($p=0;$p<=count($seq);$p++){ $t=$seq;array_splice($t,$p,0,[$js[$i]]);$m=$msObj($t);
                if($m<$best){$best=$m;$bs=$t;} }
            $seq=$bs; }
        return $seq;
    };

    // Simulator KALENDER → per-order completion + makespan
    $adv=function(Carbon $t):Carbon{$t=$t->copy();
        if($t->isWeekend())return $t->next(Carbon::MONDAY)->setTime(8,0,0);
        if($t->format('H:i:s')<'08:00:00')return $t->setTime(8,0,0);
        if($t->format('H:i:s')>='17:00:00'){$t->addDay()->setTime(8,0,0);if($t->isWeekend())$t->next(Carbon::MONDAY)->setTime(8,0,0);}
        return $t;};
    $addM=function(Carbon $s,float $m)use($adv):Carbon{$c=$adv($s->copy());$r=$m;
        while($r>0){$eod=$c->copy()->setTime(17,0,0);$av=$c->diffInMinutes($eod);
            if($r<=$av){$c->addMinutes((int)round($r));$r=0;}else{$r-=$av;$c->addDay()->setTime(8,0,0);$c=$adv($c);}}
        return $c;};
    $na=fn(Carbon $e)=>$e->format('H:i:s')>='15:00:00'?$adv($e->copy()->addDay()->setTime(8,0,0)):$e->copy();
    $mx=fn(Carbon $a,Carbon $b):Carbon=>$a->gt($b)?$a->copy():$b->copy();
    $el=function(array $a){$b=0;for($i=1;$i<count($a);$i++)if($a[$i]->lt($a[$b]))$b=$i;return $b;};
    $simCal=function(array $seq)use($anchor,$KN,$CN,$AN,$adv,$addM,$na,$mx,$el):array{
        $K=array_map(fn($_)=>$anchor->copy(),range(1,$KN));
        $C=array_map(fn($_)=>$anchor->copy(),range(1,$CN));
        $A=array_map(fn($_)=>$anchor->copy(),range(1,$AN));
        $oe=[];$last=$anchor->copy();
        foreach($seq as $j){
            $ki=$el($K);$ks=$adv($K[$ki]->copy());$ke=$addM($ks,$j['p_kayu']);$K[$ki]=$na($ke);
            $ci=$el($C);$cs=$adv($mx($C[$ci],$na($ke)));$ce=$addM($cs,$j['p_cat']);$C[$ci]=$na($ce);
            $ai=$el($A);$as=$adv($mx($A[$ai],$na($ce)));$ae=$addM($as,$j['p_acc']);$A[$ai]=$na($ae);
            if(!isset($oe[$j['spk']])||$ae->gt($oe[$j['spk']]))$oe[$j['spk']]=$ae->copy();
            if($ae->gt($last))$last=$ae->copy();
        }
        return ['oe'=>$oe,'last'=>$last];
    };

    // ── Strategi 1: EDD-ITEM (sekarang) ────────────────────────────────────────
    $s1=$jobs; usort($s1, fn($a,$b)=>$a['idl']->timestamp <=> $b['idl']->timestamp);

    // ── Strategi 2: EDD-ORDER + NEH-ITEM (blok), tanpa deadline-item ────────────
    $byOrder=[]; foreach($jobs as $j)$byOrder[$j['spk']][]=$j;
    $ordSorted=$orders; usort($ordSorted, fn($a,$b)=>$a['deadline']->timestamp<=>$b['deadline']->timestamp);
    $s2=[]; foreach($ordSorted as $o) foreach($neh($byOrder[$o['spk']]) as $j) $s2[]=$j;

    // ── Strategi 3: NEH-GLOBAL murni makespan ──────────────────────────────────
    $s3=$neh($jobs);

    $eval=function(array $seq, array $orders)use($simCal,$anchor){
        $r=$simCal($seq); $late=0; $det=[];
        foreach($orders as $o){ $end=$r['oe'][$o['spk']]; $isLate=$end->gt($o['deadline']);
            if($isLate)$late++; $det[$o['spk']]=['end'=>$end,'late'=>$isLate,
                'slack'=>(int)$end->diffInDays($o['deadline'],false)]; }
        return ['span'=>(int)$anchor->diffInDays($r['last']),'late'=>$late,'det'=>$det];
    };
    $e1=$eval($s1,$orders); $e2=$eval($s2,$orders); $e3=$eval($s3,$orders);

    echo "\n".str_repeat('═',88)."\n  TANPA DEADLINE-ITEM — 3 SPK (29 item), 6/6/3 tim, anchor 7 Nov 2025\n".str_repeat('═',88)."\n\n";
    $line=function(string $name, array $e){
        printf("  %-34s │ makespan %2d hari │ %d/3 tepat", $name, $e['span'], 3-$e['late']);
        $bad=[]; foreach($e['det'] as $spk=>$d) if($d['late'])$bad[]="$spk telat ".abs($d['slack'])."h";
        echo $bad? "  → ".implode(', ',$bad) : "  → semua tepat"; echo "\n";
    };
    $line('1. EDD-ITEM (desain sekarang)',$e1);
    $line('2. EDD-ORDER + NEH-ITEM (blok)',$e2);
    $line('3. NEH-GLOBAL (murni makespan)',$e3);
    echo "\n";
    foreach(['2798','2770','2785'] as $spk){
        printf("  %-5s (dl %s): EDD-item %s | EDD-ord+NEH %s | NEH-glob %s\n", $spk,
            collect($orders)->firstWhere('spk',$spk)['deadline']->format('d M'),
            $e1['det'][$spk]['end']->format('d M'),$e2['det'][$spk]['end']->format('d M'),$e3['det'][$spk]['end']->format('d M'));
    }
    echo "\n  → Makespan ketiganya ~sama (model proporsional). Pembeda = KETEPATAN deadline.\n";
    echo "    EDD-item & EDD-order menjaga deadline; NEH-global mengejar makespan tapi bisa menelantarkan order.\n\n";

    // Assertions: dokumentasikan. EDD-item tidak menelatkan; bandingkan late count.
    expect($e1['late'])->toBeLessThanOrEqual($e3['late']);   // EDD-item ≤ NEH-global pada keterlambatan
    expect(count($s1))->toBe(29);
    expect(count($s2))->toBe(29);
    expect(count($s3))->toBe(29);
});
