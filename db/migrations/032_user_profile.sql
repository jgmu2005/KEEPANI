-- Perfil de usuario: preferencia GLOBAL de categorías para notificaciones
-- (CSV de cat_key; vacío = todas) + categoría de los productos de Walmart
-- (para poder filtrar las liquidaciones por categoría).
ALTER TABLE users       ADD COLUMN notif_cats VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE wm_products ADD COLUMN cat_key VARCHAR(32) NULL AFTER brand;
CREATE INDEX idx_wm_catkey ON wm_products (cat_key);
