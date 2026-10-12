-- ============================================================================
--  Migración 053 — conteo diario de productos REFRESCADOS por tienda.
--  El panel de salud mide frescura ("ingestó hace Xh") pero no CUÁNTOS productos
--  se refrescaron. Una corrida que trae 6,000 en vez de 10,000 igual sale verde.
--  Esta tabla guarda, por día y tienda, cuántos productos quedaron con
--  last_date = ese día (= se les actualizó el precio). Con el histórico, el panel
--  compara contra la mediana reciente y alerta si cae mucho.
--  La puebla web/cron/crawl_counts.php (varias veces al día; se queda el valor más
--  alto del día). Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

CREATE TABLE IF NOT EXISTS crawl_counts (
  day         DATE     NOT NULL,
  store_id    INT      NOT NULL,
  updated     INT      NOT NULL DEFAULT 0,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY (day, store_id),
  KEY idx_store_day (store_id, day)
);
