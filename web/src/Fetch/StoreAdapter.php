<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Contrato común: cada tienda es un "driver" con la misma salida
 * (NormalizedProduct) pero implementación distinta por plataforma.
 */
interface StoreAdapter
{
    public function slug(): string;

    /** Trae y normaliza UN producto por su SKU; null si no existe o falla. */
    public function fetchBySku(string $sku): ?NormalizedProduct;

    /** Trae por URL (para tiendas cuya URL no se arma desde el SKU, ej. Magento). */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct;
}
