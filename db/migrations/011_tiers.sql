-- ============================================================================
--  Migración 011 — 3 niveles (free / onetime / subscriber) + límites configurables
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

ALTER TABLE users
    ADD COLUMN subscription_until DATETIME NULL DEFAULT NULL AFTER donated_at;

-- Migrar los 'donor' viejos al nuevo nivel de un-pago.
UPDATE users SET tier = 'onetime' WHERE tier = 'donor';

-- Límites por nivel (editables desde el admin). Subscriber = ilimitado (no se guarda número).
INSERT IGNORE INTO settings (k, v) VALUES
    ('limit_free',    '5'),
    ('limit_onetime', '15');
