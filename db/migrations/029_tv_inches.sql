-- Pulgadas de pantalla para el filtro de tamaño de TV (chips dentro de "Televisores").
-- Se extrae del título (NN" o NN pulgadas) para productos con cat_key='tv'.
-- Lo llena IngestService (nuevos) y cron/build_categories.php (backfill).
ALTER TABLE products ADD COLUMN tv_inches SMALLINT NULL AFTER cat_key;
CREATE INDEX idx_tv_inches ON products (tv_inches);
