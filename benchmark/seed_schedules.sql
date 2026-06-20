-- seed_schedules.sql — penyuntik JADWAL SINTETIS untuk uji endpoint BACA jadwal
-- pada volume besar tanpa menunggu lambatnya algoritma. 3 jadwal/item
-- (stasiun kayu->cat->acc, tim valid), lalu order di-set 'on_going'.
-- Jalankan SETELAH seed_bench.sql / seed_sched.sql (butuh order 'BCH-%').
-- Pemakaian:
--   psql -h 127.0.0.1 -U richardefrem -d iconspirit_bench -f seed_schedules.sql
\set ON_ERROR_STOP on

INSERT INTO production_schedules
  (production_order_item_id, station_id, team_id, start_time, end_time, status, created_at, updated_at)
SELECT i.id, st.station_id,
       (SELECT t.id FROM team t WHERE t.station_id = st.station_id ORDER BY t.id LIMIT 1),
       now() + (st.ord || ' hours')::interval,
       now() + ((st.ord+1) || ' hours')::interval,
       'scheduled', now(), now()
FROM production_order_item i
JOIN production_order o ON o.id = i.production_order_id
CROSS JOIN (VALUES (1,0),(2,1),(3,2)) AS st(station_id, ord)   -- kayu, cat, acc
WHERE o.order_id LIKE 'BCH-%';

UPDATE production_order SET status_id='on_going' WHERE order_id LIKE 'BCH-%';
