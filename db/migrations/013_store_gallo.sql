-- ============================================================================
--  Migración 013 — alta de tienda: El Gallo mas Gallo (Magento, adaptador OG por URL)
--  Nombre en ASCII (sin tilde) a propósito: evita problemas de encoding. Ver 024.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('gallo', 'El Gallo mas Gallo', 'https://www.elgallomasgallo.com.ni', 'og_meta', 'NIO', 1, 0.1500);
