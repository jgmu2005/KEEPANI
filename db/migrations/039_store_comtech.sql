-- ============================================================================
--  Migración 039 — alta de Comtech (Online40/Blazor).
--  Catálogo completo vía Search API (crawl_comtech.php). El on-demand del widget
--  va por OgMetaAdapter, porque la ficha /product/{code} trae product:price:amount.
--  https://www.comtech.com.ni — Nicaragua, ~1423 productos, NIO.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('comtech', 'Comtech', 'https://www.comtech.com.ni', 'og_meta', 'NIO', 1, 0.1500);
