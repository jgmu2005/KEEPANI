-- ============================================================================
--  Migración 051 — índice para el refrescador run.php.
--  run.php ordena los productos activos por last_date ASC (más desactualizados
--  primero). Sin índice es un filesort sobre toda la tabla; en FatCow eso puede
--  hacer que el request tarde demasiado y cron-job.org lo marque "timeout".
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

ALTER TABLE products ADD KEY idx_last_date (is_active, last_date);
