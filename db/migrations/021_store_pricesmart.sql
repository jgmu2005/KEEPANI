-- ============================================================================
--  Migración 021 — alta de PriceSmart Nicaragua (Bloomreach Discovery).
--  Bulk crawl: SOLO Electrónicos, Deportes y fitness, Ferretería y Oficina
--  (ver cli/crawl_pricesmart.php).
--
--  IVA: PriceSmart publica precios SIN IVA → tax_included=0 (+15% para comparar).
--  ⚠️ Verificar contra un checkout: si el precio de góndola YA incluye IVA,
--     cambiar tax_included a 1.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('pricesmart', 'PriceSmart Nicaragua', 'https://www.pricesmart.com', 'bloomreach', 'NIO', 0, 0.1500);
