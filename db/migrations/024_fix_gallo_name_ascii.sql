-- ============================================================================
--  Migración 024 — normaliza el nombre de Gallo a ASCII (sin tilde).
--
--  "El Gallo más Gallo" traía la "á" como byte inválido (aunque la 015 intentó
--  arreglarlo con UNHEX). En vez de pelear con el encoding, lo dejamos en ASCII
--  puro → nunca más rompe json_encode ni sale el símbolo �.
--  Ejecutar en phpMyAdmin (pestaña SQL).
-- ============================================================================

UPDATE stores SET name = 'El Gallo mas Gallo' WHERE slug = 'gallo';
