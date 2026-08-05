<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador para tiendas VTEX (Sinsa, Siman).
 *
 * Usa el API REST clásico de catálogo, ABIERTO sin autenticación:
 *   GET {base}/api/catalog_system/pub/products/search?fq=productId:{id}
 *
 * El SKU que trackeamos es el `productId` de VTEX. De ahí sacamos precio/stock
 * del seller por defecto del primer item.
 */
final class VtexAdapter implements StoreAdapter
{
    public function __construct(
        private Http   $http,
        private string $slug,
        private string $baseUrl,
        private string $currency = 'NIO',
        private bool   $taxIncluded = true,
        private float  $taxRate = 0.15
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    /** Consulta el catálogo por un campo (productId o alternateIds_RefId). */
    private function searchBy(string $field, string $value): ?array
    {
        $url = rtrim($this->baseUrl, '/')
            . '/api/catalog_system/pub/products/search?fq=' . $field . ':' . rawurlencode($value);
        $data = $this->http->getJson($url);
        return (is_array($data) && isset($data[0])) ? $data : null;
    }

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        // Primero por productId (lo que guardamos); si no aparece, por RefId
        // (referencia), porque la URL pública de VTEX a veces trae la referencia
        // en el slug en vez del productId. El resultado siempre se normaliza al
        // productId canónico (ver `sku:` abajo).
        $data = $this->searchBy('productId', $sku) ?? $this->searchBy('alternateIds_RefId', $sku);
        if (!$data) {
            return null;
        }

        return VtexMapper::map(
            $data[0], $this->slug, $this->currency, $this->taxIncluded, $this->taxRate
        );
    }
}
