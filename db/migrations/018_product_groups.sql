-- ============================================================================
--  Migración 018 — grupos de producto (comparador cross-store, slice 2a).
--  Un "grupo" = el mismo producto vendido en ≥2 tiendas, unido por identificador
--  exacto (SKU compartido de Unicomer o EAN/GTIN). Ver docs/comparador-matcher.md.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS product_groups (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_key        VARCHAR(60)  NOT NULL,          -- 'uni:<sku>' | 'ean:<ean>' (idempotencia)
    slug             VARCHAR(200) NOT NULL,          -- para /producto/{slug}
    canonical_title  VARCHAR(300) NULL,
    brand            VARCHAR(120) NULL,
    image_url        VARCHAR(500) NULL,
    member_count     INT UNSIGNED NOT NULL DEFAULT 0,
    store_count      INT UNSIGNED NOT NULL DEFAULT 0, -- en cuántas tiendas distintas está
    method           VARCHAR(20)  NOT NULL DEFAULT 'exact',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_group_match (match_key),
    UNIQUE KEY uq_group_slug (slug),
    KEY idx_group_store_count (store_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
  ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_products_group (group_id),
  ADD KEY idx_products_sku (external_sku);
