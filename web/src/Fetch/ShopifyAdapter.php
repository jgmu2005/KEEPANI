<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador para tiendas Shopify (ej. Simple Technic).
 *
 * Shopify expone el producto en JSON, abierto y sin auth:
 *   GET {base}/products/{handle}.json  →  { "product": { ... } }
 *
 * El SKU que trackeamos es el `handle` (lo que va en la URL /products/{handle}).
 */
final class ShopifyAdapter implements StoreAdapter
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

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        $handle = trim($sku, '/');
        if ($handle === '') {
            return null;
        }
        $url  = rtrim($this->baseUrl, '/') . '/products/' . rawurlencode($handle) . '.json';
        $data = $this->http->getJson($url);
        if (!is_array($data) || empty($data['product'])) {
            return null;
        }
        return ShopifyMapper::map(
            $data['product'], $this->slug, $this->baseUrl,
            $this->currency, $this->taxIncluded, $this->taxRate
        );
    }

    /** La URL de Shopify es /products/{handle}; de ahí sacamos el handle. */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        $handle = $sku;
        if (preg_match('~/products/([^/?#]+)~', $url, $m)) {
            $handle = rawurldecode($m[1]);
        }
        return $this->fetchBySku($handle);
    }
}
