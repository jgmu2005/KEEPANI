-- ============================================================================
--  Migración 026 — limpiar la cola de candidatos del matcher.
--  Los pendientes actuales se generaron con umbrales muy sueltos (la imagen
--  sola creaba matches → basura). Se borran los PENDIENTES para re-generarlos
--  con el matcher ya corregido (título = filtro principal). Los aprobados y
--  rechazados se conservan.
--  Ejecutar en phpMyAdmin (pestaña SQL), y después re-correr match.yml.
-- ============================================================================

DELETE FROM match_review WHERE status = 'pending';
