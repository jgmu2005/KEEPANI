<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador para tiendas WooCommerce (ej. E-Tech, PC System).
 *
 * Usa el Store API público (sin auth):
 *   GET {base}/wp-json/wc/store/v1/products?slug={slug}  →  [ { ... } ]
 *
 * El SKU que trackeamos es el `slug` (lo que va en /product/{slug}).
 */
final class WooAdapter implements StoreAdapter
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
        $url  = rtrim($this->baseUrl, '/') . '/wp-json/wc/store/v1/products?slug=' . rawurlencode($handle);
        $data = $this->http->getJson($url);
        if (!is_array($data) || !isset($data[0])) {
            return null;
        }
        return WooMapper::map(
            $data[0], $this->slug, $this->currency, $this->taxIncluded, $this->taxRate
        );
    }

    /** La URL de Woo es /product/{slug} (o /producto/{slug}); de ahí sale el slug. */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        $handle = $sku;
        if (preg_match('~/(?:product|producto)/([^/?#]+)~', $url, $m)) {
            $handle = rawurldecode($m[1]);
        }
        return $this->fetchBySku($handle);
    }
}
