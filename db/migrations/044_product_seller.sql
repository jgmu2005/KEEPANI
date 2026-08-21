-- Vendedor detrás de un producto en marketplaces (ej. Tizo, donde cada producto
-- lo vende un comercio distinto). Sirve para etiquetar "vendido por X" en la
-- comparación y así ser honestos cuando el mismo comercio (ej. FitShop) aparece
-- vendiendo directo Y dentro de Tizo (mismo vendedor, otro canal).
ALTER TABLE products ADD COLUMN seller VARCHAR(120) NULL AFTER brand;
