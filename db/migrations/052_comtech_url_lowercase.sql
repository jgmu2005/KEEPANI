-- ============================================================================
--  Migración 052 — pasar a MINÚSCULAS el code de los URLs de Comtech.
--  La ruta pública de Comtech es case-sensitive: /product/VX242C1 da 404, pero
--  /product/vx242c1 funciona. Guardábamos el code tal cual (mayúsculas) → el botón
--  "Ver en Comtech" daba 404. El crawler ya fue corregido para generar minúsculas;
--  esto arregla las filas YA guardadas sin esperar el próximo crawl.
--  (El external_sku NO se toca: sigue siendo el ProductCode original.)
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

-- OJO: NO filtrar por "code <> LOWER(code)" — MySQL compara case-insensitive por
-- defecto ('VX242C1' = 'vx242c1'), así que ese guard excluiría TODO y no haría nada.
-- Aplicamos LOWER a todos (es idempotente: un code ya en minúsculas no cambia).
UPDATE products p
JOIN stores s ON s.id = p.store_id
SET p.url = CONCAT(
        SUBSTRING_INDEX(p.url, '/product/', 1),
        '/product/',
        LOWER(SUBSTRING_INDEX(p.url, '/product/', -1))
    )
WHERE s.slug = 'comtech'
  AND p.url LIKE '%/product/%';
