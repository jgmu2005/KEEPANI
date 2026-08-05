-- ============================================================================
--  Migración 009 — categorías por tienda (árbol VTEX aplanado)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
--  Base para: filtro por categoría en el dashboard + crawl completo por categoría.
-- ============================================================================

CREATE TABLE IF NOT EXISTS categories (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id           INT UNSIGNED    NOT NULL,
    external_id        INT UNSIGNED    NOT NULL,      -- id de categoría en VTEX
    name               VARCHAR(255)    NOT NULL,
    parent_external_id INT UNSIGNED    NULL,          -- categoría padre (NULL = raíz)
    url                VARCHAR(500)    NULL,
    has_children       TINYINT(1)      NOT NULL DEFAULT 0,
    level              TINYINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cat_store_ext (store_id, external_id),
    KEY idx_cat_parent (store_id, parent_external_id),
    CONSTRAINT fk_cat_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
