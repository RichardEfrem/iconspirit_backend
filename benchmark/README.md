# Benchmark Response Time API — IconSpirit

Skrip untuk mereproduksi **PENGUJIAN_RESPONSE_API.md**. Semua uji berjalan di basis
data **terpisah** (`iconspirit_bench`) sehingga data SPK asli tidak tersentuh.

| Berkas | Fungsi |
|---|---|
| `seed_bench.sql` | Penyuntik order umum (3 item/order, `BCH-%`) untuk uji skala |
| `seed_sched.sql` | Penyuntik order `await_material` siap dijadwalkan (NEH+EDD) |
| `seed_schedules.sql` | Penyuntik jadwal sintetis untuk uji baca jadwal volume besar |
| `bench.sh` | Runner `ab` untuk operasi BACA (rangkum mean/p50/p95/p99/throughput) |
| `bench_run_scheduling.sh` | Ukur operasi TULIS `run-scheduling` via `curl` (satu-tembak) |
| `run_suite.sh` | Jalankan seluruh skenario BACA berurutan |

Semua perintah dijalankan dari folder `iconspirit_backend/`.

---

## Langkah 1 — Buat DB benchmark (kloning data asli)

```bash
createdb -h 127.0.0.1 -U richardefrem iconspirit_bench
pg_dump -h 127.0.0.1 -U richardefrem iconspirit_db \
  | psql -h 127.0.0.1 -U richardefrem -d iconspirit_bench
```

## Langkah 2 — Jalankan server uji (terminal terpisah, biarkan hidup)

> **PENTING — batas waktu 300 dtk.** `php artisan serve` menjalankan proses `php -S`
> ANAK yang TIDAK mewarisi `-d max_execution_time=300`, sehingga request tetap mati di
> 30 dtk (batch besar gagal HTTP 500 di detik ke-30). Agar 300 dtk benar-benar berlaku,
> jalankan server bawaan PHP **langsung dari folder `public/`** (router `server.php`
> memakai `getcwd()` sebagai docroot):

```bash
( cd public && PHP_CLI_SERVER_WORKERS=6 DB_DATABASE=iconspirit_bench \
    php -d max_execution_time=300 -d memory_limit=1G \
    -S 127.0.0.1:8001 \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php )
```

> (Untuk endpoint BACA yang cepat, `php artisan serve` biasa juga cukup; perintah di
> atas hanya wajib untuk `run-scheduling` batch besar agar tidak mati di 30 dtk.)

## Langkah 3 — Siapkan tabel token (sekali saja) lalu mint token

> **PENTING.** Aplikasi ini memakai **Sanctum SPA-cookie**, sehingga tabel
> `personal_access_tokens` TIDAK ada di basis data. Untuk benchmark via Bearer token
> (cara yang lebih mudah untuk `ab`), buat tabel itu **khusus di DB benchmark**
> (DB asli tidak tersentuh):

```bash
DB_DATABASE=iconspirit_bench php artisan migrate \
  --path=vendor/laravel/sanctum/database/migrations --force
```

Mint token dan verifikasi (di terminal lain):

```bash
DB_DATABASE=iconspirit_bench php artisan tinker --execute="
  \$u=App\Models\User::find(1); \$u->tokens()->delete();
  echo PHP_EOL.'TK:'.\$u->createToken('bench')->plainTextToken.PHP_EOL;
" 2>&1 | grep '^TK:' | sed 's/^TK://' > /tmp/bench_token.txt

# Verifikasi WAJIB: harus mencetak 200 (bukan 401). 401 = token salah → semua hasil
# benchmark akan flat ~0.01s palsu.
TOKEN=$(cat /tmp/bench_token.txt)
curl -s -o /dev/null -w "auth: %{http_code}\n" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://127.0.0.1:8001/api/auth/me
```

## Langkah 4 — (opsional) Naikkan rate limiter saat uji

Endpoint baca dibatasi `throttle` produksi. Agar tak ada respons 429 mengotori
pengukuran, naikkan sementara di `routes/api.php` (mis. `throttle:100000,1`), lalu
**kembalikan** setelah selesai.

## Langkah 5 — Jalankan pengujian

**a) Seluruh skenario BACA sekaligus:**
```bash
./benchmark/run_suite.sh
```

**b) Operasi TULIS `run-scheduling` (kuadratik NEH+EDD):**
```bash
./benchmark/bench_run_scheduling.sh
```

**c) Satu endpoint manual:**
```bash
# Pemakaian: bench.sh <n_request> <konkurensi> <path> <label>
./benchmark/bench.sh 500 10 "/api/production/order?per_page=15" "GET order"
```

## Langkah 6 — Bersihkan

```bash
lsof -ti :8001 | xargs kill -9                        # matikan server uji
dropdb -h 127.0.0.1 -U richardefrem iconspirit_bench  # hapus DB benchmark
# kembalikan throttle di routes/api.php ke nilai produksi
```

---

## Cara membaca hasil

`bench.sh` mencetak satu baris per skenario, contoh:

```
GET order (paginated)          | mean= 27.9ms | p50=30   p95=41   p99=47   max=61   |  357.58 req/s | fail=0
```

- **mean** = rata-rata response time; **p95** = 95% request selesai ≤ nilai ini
  (lebih informatif dari mean).
- **req/s** = throughput; **fail** = jumlah request gagal (idealnya 0).

`bench_run_scheduling.sh` mencetak waktu + HTTP status per sampel:

```
30 orders / 90 items -> 1.272s  [HTTP 200]
90 orders / 270 items -> 30.485s  [HTTP 200]
```

Hanya baris `[HTTP 200]` yang valid. Hasil acuan (M4, sampel nyata):

| Order | Item | Waktu |
|---:|---:|---:|
| 3 | 9 | ~0,04 s |
| 10 | 30 | ~0,11 s |
| 20 | 60 | ~0,41 s |
| 30 | 90 | ~1,27 s |
| 45 | 135 | ~4,06 s |
| 60 | 180 | ~8,93 s |
| 90 | 270 | ~30,5 s (di batas) |

---

## Troubleshooting (gejala yang pernah terjadi)

| Gejala | Penyebab | Solusi |
|---|---|---|
| Semua waktu **flat ~0,01s** di semua volume | Token salah → tiap request `401` instan (bukan algoritma) | Pastikan Langkah 3: tabel `personal_access_tokens` dibuat + cek `auth: 200` |
| `run-scheduling` mati **HTTP 500 tepat 30 dtk** | `artisan serve` tak mewariskan `-d max_execution_time=300` ke worker | Jalankan server cara **Langkah 2 (langsung `php -S` dari `public/`)** |
| Sampel berikutnya **HTTP 422 ~10 dtk** setelah satu sampel fatal | Lock penjadwalan (`Cache::lock`) belum lepas akibat fatal sebelumnya; request berikut menunggu lalu menyerah | Pakai server Langkah 2 (tak ada fatal → lock lepas bersih), atau beri jeda antar sampel |

Detail metodologi & tabel hasil acuan ada di `../../PENGUJIAN_RESPONSE_API.md` dan
`../../LAMPIRAN_PENGUJIAN_RESPONSE_API.md`.
