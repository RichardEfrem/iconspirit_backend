<?php

/**
 * Menguji gagasan: menerapkan partisi empat-kelas (critical→EDD, normal→NEH)
 * JUGA pada order on_going (material sudah tiba), supaya NEH ikut berjalan di
 * tahap eksekusi — bukan hanya forecast. Pertanyaan kunci: apakah deadline
 * tetap aman?
 *
 * Data: 3 SPK klaster (29 item), semua on_going di 7 Nov, pabrik 6/6/3.
 *
 * Membandingkan, pada batch on_going:
 *   (A) SEKARANG     : semua item EDD (NEH tidak jalan)
 *   (B) Uniform X=7  : item slack >7 hari → NEH, sisanya EDD
 *   (C) Uniform X=14 : item slack >14 hari → NEH, sisanya EDD (tight dilindungi)
 *
 * NEH memakai objektif PROGRAM (makespan*1.4 + tardiness*2.0). Keterlambatan
 * dinilai simulator kalender terhadap deadline ORDER.
 */

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('NEH uniform partition pada on_going: apakah deadline tetap aman?', function () {

    $anchor = Carbon::parse('2025-11-07 08:00:00');
    Carbon::setTestNow($anchor);
    $KN = 6; $CN = 6; $AN = 3; $CAL = 1.4; $TW = 2.0;

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

    $jobs = [];
    foreach ($orders as $o) {
        $n = count($o['items']);
        foreach ($o['items'] as $it) {
            $t = (1440.0/max(1,$n)) + ((($it[0]*$it[1])/10000.0) * $it[2] * 300.0);
            $idl = $o['deadline']->copy()->subMinutes((int)$t)->subDays(2);
            $jobs[] = ['spk'=>$o['spk'],'odl'=>$o['deadline'],
                'p_kayu'=>$t*0.4,'p_cat'=>$t*0.4,'p_acc'=>$t*0.2,'t_total'=>$t,
                'idl'=>$idl, 'dlMin'=>(float)$anchor->diffInMinutes($idl,false),
                'slackDays'=>(int)$anchor->diffInDays($idl,false)];
        }
    }

    // NEH objektif program (makespan*1.4 + tardiness*2.0)
    $obj = function(array $seq) use ($KN,$CN,$AN,$CAL,$TW): float {
        $K=array_fill(0,$KN,0.0);$C=array_fill(0,$CN,0.0);$A=array_fill(0,$AN,0.0);$ms=0.0;$cp=[];
        foreach($seq as $i=>$j){
            $ki=array_search(min($K),$K);$ek=$K[$ki]+$j['p_kayu'];$K[$ki]=$ek;
            $ci=array_search(min($C),$C);$ec=max($C[$ci],$ek)+$j['p_cat'];$C[$ci]=$ec;
            $ai=array_search(min($A),$A);$ea=max($A[$ai],$ec)+$j['p_acc'];$A[$ai]=$ea;
            $cp[$i]=$ea; if($ea>$ms)$ms=$ea;
        }
        $td=0.0; foreach($cp as $i=>$cm)$td+=max(0.0,($cm*$CAL)-$seq[$i]['dlMin']);
        return ($ms*$CAL)+($td*$TW);
    };
    $neh = function(array $js) use($obj): array {
        usort($js, fn($a,$b)=>$b['t_total']<=>$a['t_total']);
        if(count($js)<=1)return $js;
        $seq=[$js[0]];
        for($i=1;$i<count($js);$i++){$best=PHP_INT_MAX;$bs=[];
            for($p=0;$p<=count($seq);$p++){$t=$seq;array_splice($t,$p,0,[$js[$i]]);$s=$obj($t);
                if($s<$best){$best=$s;$bs=$t;}}
            $seq=$bs;}
        return $seq;
    };
    $edd = function(array $js): array { usort($js, fn($a,$b)=>$a['idl']->timestamp<=>$b['idl']->timestamp); return $js; };

    // Simulator kalender
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
    $sim=function(array $seq)use($anchor,$KN,$CN,$AN,$adv,$addM,$na,$mx,$el):array{
        $K=array_map(fn($_)=>$anchor->copy(),range(1,$KN));$C=array_map(fn($_)=>$anchor->copy(),range(1,$CN));$A=array_map(fn($_)=>$anchor->copy(),range(1,$AN));
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
    $evalSeq=function(array $seq)use($sim,$orders,$anchor){
        $r=$sim($seq);$late=0;$det=[];
        foreach($orders as $o){$end=$r['oe'][$o['spk']];$l=$end->gt($o['deadline']);if($l)$late++;
            $det[$o['spk']]=['end'=>$end->format('d M'),'late'=>$l,'slack'=>(int)$end->diffInDays($o['deadline'],false)];}
        return ['span'=>(int)$anchor->diffInDays($r['last']),'late'=>$late,'det'=>$det];
    };

    // Partisi uniform: critical(slack<=X)→EDD, normal(slack>X)→NEH
    $partition = function(int $X) use ($jobs,$edd,$neh) {
        $crit=array_filter($jobs, fn($j)=>$j['slackDays']<=$X);
        $norm=array_filter($jobs, fn($j)=>$j['slackDays']>$X);
        $nehCount=count($norm);
        $seq=array_merge($edd(array_values($crit)), $nehCount>1?$neh(array_values($norm)):array_values($norm));
        return ['seq'=>$seq,'nehCount'=>$nehCount,'critCount'=>count($crit)];
    };

    // (A) sekarang: semua EDD
    $A = $evalSeq($edd($jobs));
    // (B) uniform X=7 ; (C) uniform X=14
    $pB=$partition(7);  $B=$evalSeq($pB['seq']);
    $pC=$partition(14); $C=$evalSeq($pC['seq']);

    echo "\n".str_repeat('═',96)."\n  NEH UNIFORM PARTITION pada on_going — 3 SPK (29 item), 6/6/3, anchor 7 Nov\n".str_repeat('═',96)."\n\n";
    $row=function(string $name,array $e,int $neh=-1){
        printf("  %-40s │ makespan %2d hari │ %d/3 tepat", $name, $e['span'], 3-$e['late']);
        if($neh>=0) printf(" │ NEH atas %2d item", $neh);
        $bad=[];foreach($e['det'] as $s=>$d)if($d['late'])$bad[]="$s telat ".abs($d['slack'])."h";
        echo $bad?"  ⚠ ".implode(', ',$bad):"  ✓"; echo "\n";
    };
    $row('A. SEKARANG (semua EDD, NEH tdk jalan)', $A, 0);
    $row("B. Uniform X=7  (slack>7hr → NEH)", $B, $pB['nehCount']);
    $row("C. Uniform X=14 (slack>14hr → NEH)", $C, $pC['nehCount']);
    echo "\n  Tanggal selesai per-order (A=EDD sekarang vs C=uniform window14):\n";
    foreach(['2798','2770','2785'] as $spk){
        printf("   %-5s : A(EDD) selesai %-7s | C(uniform) selesai %-7s %s\n", $spk,
            $A['det'][$spk]['end'], $C['det'][$spk]['end'],
            $A['det'][$spk]['end']===$C['det'][$spk]['end'] ? '✓ SAMA' : '⚠ BEDA');
    }
    echo "\n  Detail item-deadline (slack dari 7 Nov):\n";
    foreach(['2798','2770','2785'] as $spk){
        $sl=array_values(array_filter($jobs, fn($j)=>$j['spk']===$spk));
        $min=min(array_map(fn($j)=>$j['slackDays'],$sl)); $max=max(array_map(fn($j)=>$j['slackDays'],$sl));
        printf("   %-5s (dl %s): slack item %d–%d hari\n", $spk, collect($orders)->firstWhere('spk',$spk)['deadline']->format('d M'), $min, $max);
    }
    echo "\n";

    // Assertions: dokumentasi temuan (tidak memaksa lulus/gagal varian)
    expect($A['late'])->toBe(0);
    expect(count($pC['seq'] ?? []))->toBeGreaterThanOrEqual(0);
});
