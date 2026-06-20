#!/bin/bash
# bench.sh — runner pengukuran operasi BACA dengan ApacheBench (ab).
# Pemakaian: ./bench.sh <jumlah_request> <konkurensi> <path_endpoint> <label>
# Contoh   : ./bench.sh 500 10 "/api/production/order?per_page=15" "GET order"
set -euo pipefail
N=$1; C=$2; PATHQ=$3; LABEL=$4
TOKEN=$(cat /tmp/bench_token.txt)

OUT=$(ab -n "$N" -c "$C" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8001${PATHQ}" 2>/dev/null)

rps=$(echo "$OUT"   | grep "Requests per second" | awk '{print $4}')
mean=$(echo "$OUT"  | grep "Time per request" | grep "(mean)" | head -1 | awk '{print $4}')
failed=$(echo "$OUT"| grep "Failed requests"  | awk '{print $3}')
# ab tidak menghitung 401/500 sebagai "Failed"; tangkap baris "Non-2xx responses".
# '|| true' penting: jika SEMUA 200, grep tak menemukan baris ini & exit 1 -> set -e mematikan skrip.
non2xx=$(echo "$OUT" | grep "Non-2xx responses" | awk '{print $3}' || true)
if [ -n "${non2xx:-}" ]; then
  echo "!! $LABEL : $non2xx respons non-2xx (mis. 401/500) — hasil TIDAK valid, cek auth/endpoint" >&2
fi
p50=$(echo "$OUT" | awk '$1=="50%"{print $2}')
p95=$(echo "$OUT" | awk '$1=="95%"{print $2}')
p99=$(echo "$OUT" | awk '$1=="99%"{print $2}')
pmax=$(echo "$OUT"| awk '$1=="100%"{print $2}')

printf "%-30s | mean=%6sms | p50=%-4s p95=%-4s p99=%-4s max=%-4s | %8s req/s | fail=%s\n" \
  "$LABEL" "$mean" "$p50" "$p95" "$p99" "$pmax" "$rps" "${failed:-0}"
