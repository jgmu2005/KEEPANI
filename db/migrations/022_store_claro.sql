-- ============================================================================
--  Migración 022 — alta de Claro Nicaragua (tienda en línea, HCL/wcaas).
--  Bulk crawl: SOLO la categoría prepago/celulares (ver cli/crawl_claro.php).
--  Precio: "precio de contado" (offer_price), ya con IVA → tax_included=1.
--  Nota: son equipos con promo Claro ("+ Promocional Claro"); el comparador
--  los liga por modelo vía el matcher difuso.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES ('claro', 'Claro Nicaragua', 'https://www.claro.com.ni', 'wcaas', 'NIO', 1, 0.1500);
