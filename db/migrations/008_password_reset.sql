-- ============================================================================
--  Migración 008 — recuperación de contraseña
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
-- ============================================================================

ALTER TABLE users
    ADD COLUMN reset_token   VARCHAR(64) NULL DEFAULT NULL AFTER verify_token,
    ADD COLUMN reset_expires DATETIME    NULL DEFAULT NULL AFTER reset_token;
