-- ============================================================================
--  Migración 030 — "Cazaofertas Walmart" (liquidaciones por bajo inventario).
--  Subsistema AISLADO (tablas wm_*), separado del tracker regular: se scrapea el
--  catálogo completo de Walmart pero se guarda UNA fila por producto (precio
--  actual) y sólo se registra un EVENTO cuando el precio baja ≥30% de su normal.
--  Correr una vez en phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS wm_products (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku           VARCHAR(64)  NOT NULL,
    title         VARCHAR(512) NULL,
    brand         VARCHAR(190) NULL,
    url           VARCHAR(768) NULL,
    image_url     VARCHAR(768) NULL,
    price_current DECIMAL(12,2) NULL,           -- último precio visto
    price_ref     DECIMAL(12,2) NULL,           -- precio "normal" (máx/lista) para medir la baja
    in_stock      TINYINT(1) NOT NULL DEFAULT 1,
    currency      VARCHAR(8) NOT NULL DEFAULT 'NIO',
    first_seen    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_drop_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sku (sku),
    KEY idx_lastdrop (last_drop_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wm_drops (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  BIGINT UNSIGNED NOT NULL,
    old_price   DECIMAL(12,2) NOT NULL,          -- precio de la captura anterior
    new_price   DECIMAL(12,2) NOT NULL,          -- precio nuevo (más barato)
    ref_price   DECIMAL(12,2) NOT NULL,          -- precio "normal" contra el que se mide
    pct         DECIMAL(5,2)  NOT NULL,          -- % de baja vs ref
    in_stock    TINYINT(1) NOT NULL DEFAULT 1,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_detected (detected_at),
    KEY idx_pct (pct),
    CONSTRAINT fk_wm_drop FOREIGN KEY (product_id) REFERENCES wm_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
