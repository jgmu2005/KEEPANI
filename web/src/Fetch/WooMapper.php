<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte UN producto del WooCommerce Store API
 * (/wp-json/wc/store/v1/products) en NormalizedProduct.
 *
 * Trackeamos por el `slug` (lo que va en la URL /product/{slug}). Los precios
 * vienen en "unidades menores": price / 10^currency_minor_unit (ej. 75210 → 752.10).
 * El campo `brands` (plugin WooCommerce Brands) da la marca cuando existe.
 */
final class WooMapper
{
    /**
     * Identificador de URL del producto (lo que trackeamos). Usa el campo `slug`;
     * si la tienda no lo popula (p.ej. gcm), lo deriva del último segmento del
     * `permalink` (…/productos/{slug}/). Así el SKU coincide con parseUrl().
     */
    public static function handle(array $p): string
    {
        $slug = trim((string) ($p['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }
        $path = (string) parse_url((string) ($p['permalink'] ?? ''), PHP_URL_PATH);
        $seg  = trim($path, '/');
        if ($seg === '') {
            return '';
        }
        $parts = explode('/', $seg);
        return rawurldecode((string) end($parts));
    }

    public static function map(
        array $p,
        string $slug,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        $handle = self::handle($p);
        $prices = $p['prices'] ?? null;
        if ($handle === '' || !is_array($prices)) {
            return null;
        }

        $minor = isset($prices['currency_minor_unit']) ? (int) $prices['currency_minor_unit'] : 2;
        $div   = 10 ** max(0, $minor);
        $toNum = static fn($v) => ($v === null || $v === '') ? null : (float) $v / $div;

        $price = $toNum($prices['price'] ?? null);
        if ($price === null || $price <= 0) {
            return null; // sin precio no hay nada que trackear
        }
        $regular   = $toNum($prices['regular_price'] ?? null);
        $listPrice = ($regular !== null && $regular > $price) ? $regular : null;

        $brand = null;
        if (!empty($p['brands']) && is_array($p['brands']) && !empty($p['brands'][0]['name'])) {
            $brand = (string) $p['brands'][0]['name'];
        }

        $image = !empty($p['images'][0]['src']) ? (string) $p['images'][0]['src'] : null;

        // Si el SKU de WooCommerce es un código de barras (EAN/UPC, 12-14 dígitos),
        // lo guardamos como EAN para el matcheo EXACTO cross-store (ej. fitshop).
        $ean    = null;
        $wooSku = trim((string) ($p['sku'] ?? ''));
        if ($wooSku !== '' && preg_match('/^\d{12,14}$/', $wooSku)) {
            $ean = $wooSku;
        }

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         $handle, // el slug es el identificador de la URL
            url:         !empty($p['permalink']) ? (string) $p['permalink'] : '',
            title:       isset($p['name']) ? html_entity_decode((string) $p['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            brand:       $brand,
            imageUrl:    $image,
            priceNative: $price,
            currency:    $currency,
            inStock:     !empty($p['is_in_stock']),
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $listPrice,
            ean:         $ean,
        );
    }
}
