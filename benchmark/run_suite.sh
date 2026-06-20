#!/bin/bash
# run_suite.sh — menjalankan seluruh skenario BACA (endpoint umum, skala, konkurensi,
# preview penjadwalan, dan tampilan jadwal). Jalankan setelah server uji + token siap.
# Pemakaian: ./run_suite.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PSQL="psql -h 127.0.0.1 -U richardefrem -d iconspirit_bench"
seed(){ $PSQL -v target=$1 -f "$HERE/seed_bench.sql" >/dev/null; }
reset(){ $PSQL -c "DELETE FROM production_order WHERE order_id LIKE 'BCH-%';" >/dev/null; }

# echo "================ SUITE ENDPOINT UMUM @ 500 order ================"
# reset; seed 500
# "$HERE/bench.sh" 500 10 "/api/auth/me"                       "GET auth/me"
# "$HERE/bench.sh" 500 10 "/api/production/order?per_page=15"  "GET order (paginated)"
# "$HERE/bench.sh" 500 10 "/api/inventory/material"            "GET material"
# "$HERE/bench.sh" 500 10 "/api/dashboard"                     "GET dashboard"

# echo "================ SKALA: pagination vs unbounded ================"
# for target in 9 100 500 1000 2000; do
#   reset; seed "$target"
#   "$HERE/bench.sh" 300 10 "/api/auth/me" "warmup" >/dev/null
#   "$HERE/bench.sh" 500 10 "/api/production/order?per_page=15" "$target ord PAGINATED"
#   "$HERE/bench.sh" 500 10 "/api/production/order"             "$target ord UNBOUNDED"
# done

# echo "================ KONKURENSI (order paginated) ================"
# reset; seed 500
# for c in 1 5 10 25 50 100; do
#   "$HERE/bench.sh" 1000 "$c" "/api/production/order?per_page=15" "concurrency=$c"
# done

# echo "================ PREVIEW PENJADWALAN (await_material) ================"
# # Endpoint ini menjalankan ulang simulasi NEH TIAP request tanpa cache -> sangat lambat
# # pada volume besar (lihat laporan C.3). n & c dikecilkan utk 60/90 ord agar tak berjam-jam;
# # nilai mean/p95 tetap valid, hanya jumlah sampel yang lebih sedikit.
# reset
# BASE=$($PSQL -tc "SELECT count(*) FROM production_order;" | xargs)   # jumlah order asli (non-BCH)
# # format tiap baris: "orders n c"
# for row in "9 100 10" "30 50 10" "60 20 5" "90 8 4"; do
#   set -- $row; ord=$1; n=$2; c=$3
#   reset; $PSQL -v target=$((BASE+ord)) -f "$HERE/seed_sched.sql" >/dev/null
#   "$HERE/bench.sh" "$n" "$c" "/api/production/order?status_id=await_material&per_page=15" \
#     "await_material ${ord} ord (n=$n c=$c)"
# done

echo "================ TAMPILAN JADWAL (cache vs tanpa cache) ================"
# Membuktikan efek cache:
#   ongoing-progress -> Cache::remember('ongoing_progress', 15 dtk)  => konstan walau volume naik
#   team-schedules   -> TANPA cache (query DB tiap request)          => tumbuh linear
# Volume jadwal = order x 9 (3 item/order x 3 stasiun kayu/cat/acc).
#   30 order=270, 100 order=900, 300 order=2.700, 600 order=5.400 jadwal.
reset
BASE=$($PSQL -tc "SELECT count(*) FROM production_order;" | xargs)   # jumlah order asli (non-BCH)
for ord in 30 100 300 600; do
  sched=$((ord*9))
  reset
  $PSQL -v target=$((BASE+ord)) -f "$HERE/seed_sched.sql" >/dev/null   # N order await + 3 item/order
  $PSQL -f "$HERE/seed_schedules.sql" >/dev/null                       # 9 jadwal/order, set on_going
  $PSQL -c "DELETE FROM cache;" >/dev/null                             # kosongkan cache: request pertama hitung ulang, sisanya hit cache
  echo "-------- ~$sched jadwal ($ord order) --------"
  "$HERE/bench.sh" 200 10 "/api/production/schedule/ongoing-progress" "ongoing-progress CACHE  ($sched jadwal)"
  "$HERE/bench.sh" 200 10 "/api/production/schedule/team-schedules"   "team-schedules NOCACHE ($sched jadwal)"
done

echo "================ SELESAI ================"
reset
