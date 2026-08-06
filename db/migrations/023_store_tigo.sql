-- ============================================================================
--  Migración 023 — alta de Tigo Nicaragua (catálogo prepago, Angular SSR + JSON-LD).
--  Bulk crawl: SOLO el catálogo prepago de celulares (ver cli/crawl_tigo.php).
--  Precio de contado con IVA → tax_included=1.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('tigo', 'Tigo Nicaragua', 'https://www.tigo.com.ni', 'jsonld', 'NIO', 1, 0.1500);
