-- Conteo de vistas de detalle por producto y fuente (web = clic en la tarjeta,
-- ext = consulta desde la extensión de Chrome). Agregado por día para que sea
-- compacto y rápido. Lo incrementa api/history.php.
CREATE TABLE IF NOT EXISTS product_hits (
    product_id BIGINT UNSIGNED NOT NULL,
    source     VARCHAR(8) NOT NULL DEFAULT 'web',   -- 'web' | 'ext'
    day        DATE NOT NULL,
    hits       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, source, day),
    KEY idx_hits_day (day),
    KEY idx_hits_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
