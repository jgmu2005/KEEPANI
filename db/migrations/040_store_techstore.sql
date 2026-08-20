-- ============================================================================
--  Migración 040 — alta de Tech Store Nicaragua (SPA estática en Netlify).
--  Catálogo embebido en app.js (crawl_techstore.php). Nicho: audio gamer, moto,
--  smartwatches, powerbanks. ~52 productos, NIO. Sin ficha por producto → el
--  widget/on-demand no aplica (platform 'static', sin adaptador).
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('techstore', 'Tech Store Nicaragua', 'https://techstore-nicaragua.com', 'static', 'NIO', 1, 0.1500);
