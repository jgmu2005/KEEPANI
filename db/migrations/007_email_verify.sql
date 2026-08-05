-- ============================================================================
--  Migración 007 — verificación de correo (doble opt-in)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
--  users.is_verified ya existe; agregamos el token de verificación.
--  (Los usuarios ya registrados quedan con is_verified=1, no se les molesta.)
-- ============================================================================

ALTER TABLE users
    ADD COLUMN verify_token VARCHAR(64) NULL DEFAULT NULL AFTER is_verified;
