-- ============================================================================
--  Migración 012 — tipo de cambio USD (BCN)
--  Ejecutar una vez en phpMyAdmin. Siembra el valor oficial actual del BCN.
-- ============================================================================

INSERT IGNORE INTO settings (k, v) VALUES ('usd_rate', '36.6243');
