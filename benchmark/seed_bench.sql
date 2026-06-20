-- seed_bench.sql — penyuntik data UMUM untuk uji skala (pagination vs unbounded).
-- Menaikkan jumlah order ke :target, tiap order 3 item; order_id berawalan 'BCH-'.
-- Pemakaian:
--   psql -h 127.0.0.1 -U richardefrem -d iconspirit_bench -v target=500 -f seed_bench.sql
\set ON_ERROR_STOP on

INSERT INTO production_order
  (customer_id, order_id, nama_customer, alamat_customer, tanggal_order,
   status_id, is_urgent, production_deadline, created_at, updated_at)
SELECT 1 + (g % 10), 'BCH-' || lpad(g::text, 6, '0'),
       'Customer ' || g, 'Jl. Benchmark No. ' || g || ', Tegal',
       CURRENT_DATE - ((g % 180)::int),
       (ARRAY['new','await_material','on_going','finished'])[1 + (g % 4)],
       (g % 7 = 0), CURRENT_DATE - ((g % 180)::int) + 35, now(), now()
FROM generate_series((SELECT count(*) FROM production_order)+1, :target) g;

INSERT INTO production_order_item
  (production_order_id, product_id, panjang, tinggi, quantity,
   keterangan, production_time, created_at, updated_at)
SELECT po.id, 1+((po.id+g)%14), 60+((po.id*7+g)%180), 40+((po.id*3+g)%160),
       1+((po.id+g)%5), 'Item benchmark '||g, 120+((po.id+g)%600), now(), now()
FROM production_order po CROSS JOIN generate_series(1,3) g
WHERE po.order_id LIKE 'BCH-%'
  AND NOT EXISTS (SELECT 1 FROM production_order_item i WHERE i.production_order_id=po.id);
