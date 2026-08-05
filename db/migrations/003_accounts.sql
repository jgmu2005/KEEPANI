-- ============================================================================
--  Migración 003 — cuentas y niveles (free / donor)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
--  La tabla `users` ya existe (schema.sql). Solo agregamos nivel y donación.
-- ============================================================================

ALTER TABLE users
    ADD COLUMN tier       VARCHAR(20) NOT NULL DEFAULT 'free' AFTER password_hash,
    ADD COLUMN donated_at DATETIME    NULL     DEFAULT NULL   AFTER is_verified;

-- Nivel: 'free' (2 productos) | 'donor' (10). El tope real vive en Auth::LIMITS
-- (PHP), así se pueden cambiar los números sin tocar la base.
