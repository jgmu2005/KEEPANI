<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte UN item del GraphQL de Magento 2 en NormalizedProduct.
 *
 * Query de origen (ver crawl_magento.php / MagentoAdapter):
 *   products(...){ items{ name sku url_key stock_status
 *     price_range{ minimum_price{ final_price{value currency} regular_price{value} } }
 *     small_image{url} } }
 *
 * Trackeamos por `url_key` (lo que va en la URL /{url_key}.html), como Shopify por
 * handle. La marca se pasa por config (ej. 'Samsung' en shop.samsung.com); el resto
 * de tiendas Magento multi-marca la dejan null y se infiere del título.
 */
final class MagentoMapper
{
    public static function map(
        array $p,
        string $slug,
        string $baseUrl,
        string $currency,
        bool $taxIncluded,
        float $taxRate,
        ?string $defaultBrand = null
    ): ?NormalizedProduct {
        $urlKey = trim((string) ($p['url_key'] ?? ''));
        $min    = $p['price_range']['minimum_price'] ?? null;
        if ($urlKey === '' || !is_array($min)) {
            return null;
        }

        $final = isset($min['final_price']['value']) ? (float) $min['final_price']['value'] : null;
        if ($final === null || $final <= 0) {
            return null; // sin precio no hay nada que trackear
        }
        $regular   = isset($min['regular_price']['value']) ? (float) $min['regular_price']['value'] : null;
        $listPrice = ($regular !== null && $regular > $final) ? $regular : null;
        $cur       = !empty($min['final_price']['currency']) ? (string) $min['final_price']['currency'] : $currency;

        $image = !empty($p['small_image']['url']) ? (string) $p['small_image']['url'] : null;

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         $urlKey, // el url_key es el identificador de la URL
            url:         rtrim($baseUrl, '/') . '/' . $urlKey . '.html',
            title:       isset($p['name']) ? html_entity_decode((string) $p['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            brand:       $defaultBrand,
            imageUrl:    $image,
            priceNative: $final,
            currency:    $cur,
            inStock:     ($p['stock_status'] ?? '') === 'IN_STOCK',
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $listPrice,
        );
    }
}
