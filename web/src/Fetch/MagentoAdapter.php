<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador para tiendas Magento 2 con GraphQL abierto (ej. shop.samsung.com).
 *
 * GraphQL endpoint: {baseUrl}/graphql  (POST). El header `Store` selecciona la
 * vista de tienda (código de país; lo derivamos del path /latin/{cc}/).
 * El SKU que trackeamos es el `url_key` (lo que va en la URL /{url_key}.html).
 */
final class MagentoAdapter implements StoreAdapter
{
    /** Campos que pedimos de cada producto (compartido con el crawler). */
    public const FIELDS = 'name sku url_key stock_status '
        . 'price_range{minimum_price{final_price{value currency} regular_price{value}}} '
        . 'small_image{url}';

    public function __construct(
        private Http   $http,
        private string $slug,
        private string $baseUrl,
        private string $currency = 'USD',
        private bool   $taxIncluded = true,
        private float  $taxRate = 0.15,
        private ?string $defaultBrand = null
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    /** Código de vista de tienda (header Store): el último segmento del path (ej. ni). */
    private function storeCode(): string
    {
        $path  = trim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
        $parts = $path === '' ? [] : explode('/', $path);
        return $parts ? (string) end($parts) : 'default';
    }

    private function query(string $gql): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/graphql';
        $res = $this->http->postJson($url, ['query' => $gql], ['Store: ' . $this->storeCode()]);
        if (($res['status'] ?? 0) !== 200) {
            return null;
        }
        $j = json_decode((string) ($res['body'] ?? ''), true);
        return is_array($j) ? $j : null;
    }

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        $urlKey = trim($sku, '/');
        if ($urlKey === '') {
            return null;
        }
        // Escapamos comillas del url_key en la query GraphQL.
        $safe = str_replace(['"', '\\'], '', $urlKey);
        $gql  = '{products(filter:{url_key:{eq:"' . $safe . '"}}){items{' . self::FIELDS . '}}}';
        $j    = $this->query($gql);
        $item = $j['data']['products']['items'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }
        return MagentoMapper::map(
            $item, $this->slug, $this->baseUrl,
            $this->currency, $this->taxIncluded, $this->taxRate, $this->defaultBrand
        );
    }

    /** URL Magento: /{url_key}.html (no las de categoría /shop/...). */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        $urlKey = $sku;
        if (preg_match('~/([^/?#]+)\.html(?:[?#]|$)~', $url, $m)) {
            $urlKey = rawurldecode($m[1]);
        }
        return $this->fetchBySku($urlKey);
    }
}
