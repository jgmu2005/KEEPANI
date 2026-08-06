<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador para tiendas server-rendered que exponen meta tags Open Graph
 * de producto en el HTML crudo (Copasa).
 *
 *   GET {base}/Product/Detail/{SKU} incluye:
 *     <meta property="product:price:amount"   content="12999.00">
 *     <meta property="product:price:currency" content="NIO">
 *     <meta property="product:availability"   content="in stock">
 *
 * NOTA: en Copasa el NOMBRE del producto es client-side (no está en el HTML),
 * por eso title puede venir null aquí; se enriquece al registrar el producto.
 */
final class OgMetaAdapter implements StoreAdapter
{
    public function __construct(
        private Http   $http,
        private string $slug,
        private string $baseUrl,
        private string $productPath = '/Product/Detail/{sku}',
        private string $currencyFallback = 'NIO',
        private bool   $taxIncluded = true,
        private float  $taxRate = 0.15
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        if (!str_contains($this->productPath, '{sku}')) {
            return null; // esta tienda no arma la URL desde el sku → usar fetchByUrl
        }
        $url = rtrim($this->baseUrl, '/') . str_replace('{sku}', rawurlencode($sku), $this->productPath);
        return $this->fetchByUrl($url, $sku);
    }

    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        $html = $this->http->get($url, ['Accept: text/html']);
        if ($html === null) {
            return null;
        }

        $priceRaw = $this->meta($html, 'product:price:amount');
        if ($priceRaw === null) {
            return null; // sin precio no hay nada que trackear
        }

        $availRaw = strtolower((string) $this->meta($html, 'product:availability'));
        // Si la tienda no publica disponibilidad (ej. Unicomer), asumimos en stock.
        $inStock  = $availRaw === ''
            ? true
            : (str_contains($availRaw, 'in stock') || str_contains($availRaw, 'instock'));

        // Precio de lista (Magento: data-price-amount; el mayor > precio = precio tachado).
        $list = null;
        if (preg_match_all('/data-price-amount="([0-9.]+)"/', $html, $mm)) {
            $max = max(array_map('floatval', $mm[1]));
            if ($max > (float) $priceRaw) {
                $list = $max;
            }
        }

        return new NormalizedProduct(
            storeSlug:   $this->slug,
            sku:         $sku,
            url:         $this->meta($html, 'og:url') ?: $url,
            title:       $this->meta($html, 'og:title') ?: null,
            brand:       $this->meta($html, 'product:brand') ?: null,
            imageUrl:    $this->meta($html, 'og:image'),
            priceNative: (float) $priceRaw,
            currency:    $this->meta($html, 'product:price:currency') ?: $this->currencyFallback,
            inStock:     $inStock,
            taxIncluded: $this->taxIncluded,
            taxRate:     $this->taxRate,
            listPrice:   $list,
        );
    }

    /** Extrae content de un <meta property="X"> (tolera orden de atributos). */
    private function meta(string $html, string $property): ?string
    {
        $prop = preg_quote($property, '/');
        if (preg_match('/<meta[^>]+property=["\']' . $prop . '["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*property=["\']' . $prop . '["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }
        return null;
    }
}
