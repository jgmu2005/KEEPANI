-- ============================================================================
--  Migración 050 — corregir la moneda de Electro Frío en filas YA ingestadas.
--  El primer crawl etiquetó los precios como NIO cuando en realidad la tienda está
--  en USD (WooMapper usaba el currency del config en vez del currency_code de la
--  API; ya corregido). El VALOR del precio es correcto (es el monto real en USD);
--  solo la etiqueta de moneda estaba mal, por eso US$636.60 se veía como C$636.60.
--
--  Reetiquetamos a USD en las DOS tablas donde vive la moneda: price_history
--  (histórico por fila) y products.last_currency (caché del último precio).
--  producto.php ya convierte USD→C$ con el usd_rate al mostrar/comparar.
--  Ejecutar una vez en phpMyAdmin/Adminer (después de la 049).
-- ============================================================================

UPDATE price_history ph
JOIN products p ON p.id = ph.product_id
JOIN stores   s ON s.id = p.store_id
SET ph.currency = 'USD'
WHERE s.slug = 'electrofrioni' AND ph.currency <> 'USD';

UPDATE products p
JOIN stores s ON s.id = p.store_id
SET p.last_currency = 'USD'
WHERE s.slug = 'electrofrioni' AND (p.last_currency IS NULL OR p.last_currency <> 'USD');
