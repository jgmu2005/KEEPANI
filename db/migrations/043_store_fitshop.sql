-- ============================================================================
--  Migración 043 — alta de FitShop (fitshop.com.ni). Suplementos y fitness,
--  WooCommerce. ~408 productos, NIO, con EAN en el SKU (matcheo cross-store).
--  Lo crawlea crawl_woocommerce.php (mismo adaptador que etech/pcsystemni).
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('fitshop', 'FitShop', 'https://fitshop.com.ni', 'woocommerce', 'NIO', 1, 0.1500);
