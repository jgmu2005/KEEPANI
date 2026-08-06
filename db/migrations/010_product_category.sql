-- ============================================================================
--  Migración 010 — categoría del producto (para filtrar por categoría)
--  Ejecutar una vez en phpMyAdmin. Se llena en el próximo crawl (código nuevo).
-- ============================================================================

ALTER TABLE products
    ADD COLUMN category_external_id INT UNSIGNED NULL DEFAULT NULL AFTER external_sku,
    ADD KEY idx_products_category (store_id, category_external_id);
