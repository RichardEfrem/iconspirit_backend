#!/bin/bash
# bench_run_scheduling.sh — mengukur operasi TULIS run-scheduling (algoritma NEH+EDD).
# Tidak bisa pakai 'ab': sekali jalan item langsung punya jadwal -> panggilan berikut no-op.
# Maka diukur satu-tembak berulang dengan curl (time_total), me-reset jadwal sebelum tiap sampel.
# Mencetak HTTP status tiap sampel; jika bukan 200 (mis. 401/500), waktu TIDAK valid.
# Pemakaian: ./bench_run_scheduling.sh
set -euo pipefail
TOKEN=$(cat /tmp/bench_token.txt)
HERE="$(cd "$(dirname "$0")" && pwd)"
q(){ psql -h 127.0.0.1 -U richardefrem -d iconspirit_bench -q -t "$@"; }

# Jumlah order non-BCH (data asli) — dipakai agar 'orders' = jumlah order BCH yang benar.
q -c "DELETE FROM production_order WHERE order_id LIKE 'BCH-%';" >/dev/null
BASE=$(q -c "SELECT count(*) FROM production_order;" | xargs)

for orders in 3 5 10 15 20 30 45 60 90; do
  q -c "DELETE FROM production_order WHERE order_id LIKE 'BCH-%';" >/dev/null
  q -v target=$((BASE+orders)) -f "$HERE/seed_sched.sql" >/dev/null
  items=$(q -c "SELECT count(*) FROM production_order_item i JOIN production_order o
                ON o.id=i.production_order_id WHERE o.order_id LIKE 'BCH-%';" | xargs)
  for n in 1 2 3; do                           # 3 sampel
    q -c "DELETE FROM production_schedules;" >/dev/null   # reset agar item 'belum terjadwal'
    resp=$(curl -s -X POST \
          -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
          -o /dev/null -w "%{http_code} %{time_total}" \
          "http://127.0.0.1:8001/api/production/order/run-scheduling")
    code=${resp%% *}; t=${resp##* }
    if [ "$code" = "200" ]; then
      echo "$orders orders / $items items -> ${t}s  [HTTP $code]"
    else
      echo "$orders orders / $items items -> ${t}s  [HTTP $code]"
    fi
  done
done
