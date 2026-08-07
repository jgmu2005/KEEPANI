-- Categorías cross-store por keywords del título (chips del catálogo).
-- Se llena con CategoryClassifier (IngestService en la ingesta + backfill
-- cron/build_categories.php). Independiente de las categorías por-tienda
-- (category_external_id), que solo servían para una tienda.
ALTER TABLE products ADD COLUMN cat_key VARCHAR(32) NULL AFTER model_norm;
CREATE INDEX idx_cat_key ON products (cat_key);
