-- ============================================================================
--  Migración 037 — alta de Simple Technic (Shopify, adaptador por products.json)
--  https://simpletechnic.com — Nicaragua. ~386 productos, moneda NIO.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('simpletechnic', 'Simple Technic', 'https://simpletechnic.com', 'shopify', 'NIO', 1, 0.1500);
