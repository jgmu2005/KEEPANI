-- ============================================================================
--  Migración 038 — alta de tiendas WooCommerce (adaptador por Store API)
--  E-Tech (~439 productos) y PC System (~89). Nicaragua, moneda NIO.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('etech',      'E-Tech',    'https://etech.com.ni',   'woocommerce', 'NIO', 1, 0.1500),
  ('pcsystemni', 'PC System', 'https://pcsystemni.com', 'woocommerce', 'NIO', 1, 0.1500);
