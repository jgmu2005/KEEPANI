-- ============================================================================
--  Migración 015 — corrige el nombre de "El Gallo más Gallo".
--
--  La migración 013, al importarse por phpMyAdmin, guardó la "á" como byte
--  latin1 inválido → json_encode() fallaba y /api/products.php devolvía vacío.
--
--  Reescribimos el nombre usando los BYTES UTF-8 exactos (UNHEX), así queda
--  bien sin importar el charset del cliente que ejecute esta migración.
--    456C2047616C6C6F206DC3A1732047616C6C6F = "El Gallo más Gallo"
-- ============================================================================

UPDATE stores
   SET name = CONVERT(UNHEX('456C2047616C6C6F206DC3A1732047616C6C6F') USING utf8mb4)
 WHERE slug = 'gallo';
