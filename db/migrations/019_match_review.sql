-- ============================================================================
--  Migración 019 — cola de revisión de matches difusos (comparador slice 2b).
--  Candidatos "mismo producto" entre tiendas que NO comparten identificador
--  exacto (cruce de islas VTEX/Unicomer/Gallo), detectados por marca + imagen
--  (dHash) + título + atributos. El admin aprueba/rechaza; al aprobar se
--  fusionan en un product_group. Ver docs/comparador-matcher.md (Nivel B/C/D).
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS match_review (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_a_id  BIGINT UNSIGNED NOT NULL,           -- siempre a_id < b_id (par canónico)
    product_b_id  BIGINT UNSIGNED NOT NULL,
    score         TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0..100 confianza
    img_distance  TINYINT UNSIGNED NULL,               -- Hamming dHash (null si falta)
    jaccard       DECIMAL(4,3) NOT NULL DEFAULT 0,      -- similitud de título 0..1
    method        VARCHAR(16) NOT NULL DEFAULT 'title', -- 'image' | 'title'
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pair (product_a_id, product_b_id),
    KEY idx_status_score (status, score),
    CONSTRAINT fk_mr_a FOREIGN KEY (product_a_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_mr_b FOREIGN KEY (product_b_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
