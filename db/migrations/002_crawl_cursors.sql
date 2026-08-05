-- ============================================================================
--  Migración 002 — cursor del seed masivo (crawler VTEX)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
--  Guarda por tienda hasta dónde llegó el crawler, para avanzar "poco a poco".
-- ============================================================================

CREATE TABLE IF NOT EXISTS crawl_cursors (
    store_slug  VARCHAR(40)  NOT NULL,
    next_from   INT UNSIGNED NOT NULL DEFAULT 0,   -- próximo offset a pedir
    total_seen  INT UNSIGNED NOT NULL DEFAULT 0,   -- productos ingeridos acumulados
    done        TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = catálogo recorrido
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (store_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
