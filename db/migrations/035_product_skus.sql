-- Mapa refId-de-SKU -> producto. En VTEX (Siman sobre todo) un producto con
-- tallas/colores tiene VARIOS refId de SKU pero un solo productId; la URL y la
-- "Referencia" que muestra la ficha usan cualquiera de esos refId, distinto al
-- productId que guardamos en products.external_sku. Sin este mapa, la extensión
-- decía "Aún no seguimos este producto" aunque SÍ lo tuviéramos.
--
-- Se puebla en CADA ingesta (IngestService) a partir de VtexMapper. Lo consulta
-- ProductRepository::resolve para reconocer el producto por cualquier refId,
-- con un lookup local (rápido y confiable), sin llamar a la tienda en vivo.
CREATE TABLE IF NOT EXISTS product_skus (
    store_id   INT NOT NULL,
    ref_id     VARCHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (store_id, ref_id),
    KEY idx_pskus_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
