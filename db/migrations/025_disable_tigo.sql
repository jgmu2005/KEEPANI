-- ============================================================================
--  Migración 025 — cancelar Tigo.
--  Tigo bloquea la IP de GitHub y de FatCow (403) → no se puede crawlear.
--  Se desactiva la tienda (sale del dropdown y del sitio). Reversible.
--  Ejecutar en phpMyAdmin (pestaña SQL).
-- ============================================================================

UPDATE stores SET is_active = 0 WHERE slug = 'tigo';
