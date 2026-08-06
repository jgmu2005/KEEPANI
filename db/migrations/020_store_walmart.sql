-- ============================================================================
--  Migración 020 — alta de Walmart Nicaragua (VTEX).
--  Bulk crawl: SOLO la sección Electrónica (categoría 13) — ver crawl_categories.
--  On-demand: cualquier link de walmart.com.ni se puede trackear (parseUrl mapea
--  por dominio contra base_url).
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('walmart', 'Walmart Nicaragua', 'https://www.walmart.com.ni', 'vtex', 'NIO', 1, 0.1500);
