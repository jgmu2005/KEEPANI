-- ============================================================================
--  Migración 013 — alta de tienda: El Gallo más Gallo (Magento, adaptador OG por URL)
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('gallo', 'El Gallo más Gallo', 'https://www.elgallomasgallo.com.ni', 'og_meta', 'NIO', 1, 0.1500);
