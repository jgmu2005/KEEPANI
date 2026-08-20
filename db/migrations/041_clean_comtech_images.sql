-- Limpia las imágenes de comtech que apuntan al storage S3 de OTRO tenant
-- (ej. LBRRRCL): responden 403 (privadas), rompen el hasheo del comparador y se
-- ven como imagen rota. Las dejamos en NULL → el front muestra placeholder limpio.
-- Las imágenes del tenant COMTECH (que sí funcionan) no se tocan.
UPDATE products p
   JOIN stores s ON s.id = p.store_id
   SET p.image_url = NULL
 WHERE s.slug = 'comtech'
   AND p.image_url LIKE '%amazonaws.com%'
   AND p.image_url NOT LIKE '%/COMTECH/%';
