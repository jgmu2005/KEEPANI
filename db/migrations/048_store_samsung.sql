-- ============================================================================
--  Migración 048 — alta de Samsung Nicaragua (shop.samsung.com/latin/ni).
--  Tienda OFICIAL de Samsung, corre en Magento 2 con GraphQL abierto. Precios en
--  USD (el sitio convierte a C$ con el usd_rate para comparar). Se crawlea con
--  crawl_magento.php (categoría Smartphones → productos vía GraphQL).
--  El adaptador Magento es reutilizable para otras tiendas Magento a futuro.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('samsung', 'Samsung Nicaragua', 'https://shop.samsung.com/latin/ni', 'magento', 'USD', 1, 0.1500);
