-- ============================================================================
--  Migración 004 — ajustes del sitio (para el panel de administración)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    k          VARCHAR(60) NOT NULL,
    v          TEXT        NULL,
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores por defecto (INSERT IGNORE no pisa si ya existen).
INSERT IGNORE INTO settings (k, v) VALUES
    ('site_name',     'Ojo al Precio'),
    ('tagline',       'Historial de precios · Tiendas de Nicaragua'),
    ('hero_text',     '¿Esa "oferta" es real? Pegá el enlace de un producto o buscá entre los que seguimos y mirá cómo se ha movido su precio en el tiempo.'),
    ('logo_emoji',    '👁️'),
    ('donate_kofi',   ''),
    ('donate_paypal', ''),
    ('footer_note',   'Hecho en Nicaragua 🇳🇮 · Los precios son referenciales y pueden variar. Verificá en la tienda antes de comprar.');
