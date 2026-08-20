<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte UN producto del JSON de Shopify (/products.json o
 * /products/{handle}.json) en NormalizedProduct.
 *
 * Trackeamos a nivel PRODUCTO (por su `handle`, que es lo que va en la URL):
 *   - precio  = variante representativa (primera disponible, o la primera).
 *   - lista   = compare_at_price si es mayor al precio (precio tachado).
 *   - stock   = alguna variante disponible.
 *   - marca   = vendor.
 * Nota: la API pública de Shopify NO expone el EAN/barcode, así que `ean` va null
 * (el matcheo cross-store cae al difuso por marca+modelo o a agrupación manual).
 */
final class ShopifyMapper
{
    public static function map(
        array $p,
        string $slug,
        string $baseUrl,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        $handle   = (string) ($p['handle'] ?? '');
        $variants = $p['variants'] ?? [];
        if ($handle === '' || !$variants) {
            return null;
        }

        // Variante representativa: la primera DISPONIBLE; si ninguna, la primera.
        $rep = $variants[0];
        foreach ($variants as $v) {
            if (!empty($v['available'])) { $rep = $v; break; }
        }

        $price = isset($rep['price']) ? (float) $rep['price'] : null;
        if ($price === null || $price <= 0) {
            return null; // sin precio no hay nada que trackear
        }
        $compare = isset($rep['compare_at_price']) && $rep['compare_at_price'] !== null
            ? (float) $rep['compare_at_price'] : null;
        $listPrice = ($compare !== null && $compare > $price) ? $compare : null;

        // Stock: alguna variante disponible. Ojo: /products.json (catálogo) trae
        // `available`, pero /products/{handle}.json (individual, on-demand) NO lo
        // incluye; si no viene en ninguna variante, asumimos disponible (el crawl
        // diario lo corrige con el dato real).
        $hasAvail = false; $inStock = false;
        foreach ($variants as $v) {
            if (array_key_exists('available', $v)) {
                $hasAvail = true;
                if (!empty($v['available'])) { $inStock = true; break; }
            }
        }
        if (!$hasAvail) { $inStock = true; }

        $image = null;
        if (!empty($p['images'][0]['src'])) {
            $image = (string) $p['images'][0]['src'];
        } elseif (!empty($p['image']['src'])) {
            $image = (string) $p['image']['src'];
        }

        // EAN/barcode: sólo lo trae el endpoint individual (/products/{handle}.json),
        // no el catálogo. Cuando viene, ayuda al matcheo exacto cross-store.
        $ean = !empty($rep['barcode']) ? (string) $rep['barcode'] : null;

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         $handle, // el handle es el identificador de la URL
            url:         rtrim($baseUrl, '/') . '/products/' . $handle,
            title:       isset($p['title']) ? (string) $p['title'] : null,
            brand:       !empty($p['vendor']) ? (string) $p['vendor'] : null,
            imageUrl:    $image,
            priceNative: $price,
            currency:    $currency,
            inStock:     $inStock,
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $listPrice,
            ean:         $ean,
        );
    }
}
