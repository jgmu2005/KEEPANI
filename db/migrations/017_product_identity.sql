-- ============================================================================
--  Migración 017 — enriquecimiento de identidad para el comparador cross-store.
--  Ver docs/comparador-matcher.md (Fase 1).
--    ean         : GTIN/código de barras (VTEX lo expone; puede venir vacío)
--    brand_norm  : marca normalizada (para "blocking" del matcher)
--    model_norm  : título/modelo normalizado (unidades, sin acentos ni ruido)
--    img_dhash   : hash perceptual dHash de la imagen (hex de 64 bits), 1 vez
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

ALTER TABLE products
  ADD COLUMN ean        VARCHAR(20)  NULL AFTER external_sku,
  ADD COLUMN brand_norm VARCHAR(120) NULL AFTER brand,
  ADD COLUMN model_norm VARCHAR(200) NULL AFTER brand_norm,
  ADD COLUMN img_dhash  CHAR(16)     NULL AFTER image_url,
  ADD KEY idx_products_ean (ean),
  ADD KEY idx_products_brand_norm (brand_norm);
