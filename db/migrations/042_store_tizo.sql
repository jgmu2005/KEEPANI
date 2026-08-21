-- ============================================================================
--  Migración 042 — alta de Tizo (soytizo.com/ni). Marketplace de comercios de
--  tecnología, tratado como UNA tienda retail (Tizo cobra y despacha). API REST
--  api.tizo.app con token de invitado; crawler crawl_tizo.php. Moneda NIO.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('tizo', 'Tizo', 'https://www.soytizo.com', 'tizo', 'NIO', 1, 0.1500);
