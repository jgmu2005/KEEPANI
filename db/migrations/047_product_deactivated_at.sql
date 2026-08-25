-- ============================================================================
--  Migración 047 — columna deactivated_at en products.
--  Sella la fecha/hora en que un producto se marcó inactivo (delisted) para poder
--  reportar "salidas por día" en el dashboard de tiendas. prune_delisted la escribe
--  al marcar is_active=0; IngestService la limpia (NULL) al reactivar un producto.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

ALTER TABLE products
  ADD COLUMN deactivated_at DATETIME NULL DEFAULT NULL AFTER last_seen_at;

-- Índice para los conteos por fecha del dashboard (salidas por día).
CREATE INDEX idx_products_deactivated_at ON products (deactivated_at);
