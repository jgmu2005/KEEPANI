<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte UN producto del JSON de Tizo (api.tizo.app) en NormalizedProduct.
 * Tizo es un marketplace, pero lo tratamos como UNA sola tienda retail (ellos
 * cobran y despachan). El precio efectivo es offerPrice; price es el regular
 * (si es mayor, va como precio de lista / tachado).
 *
 *   producto: { productId, productName, price, offerPrice, brand, filePath,
 *               motherCategory, idProductOption, statusProduct, quantity }
 *   URL pública: {base}/home/product/{productId}/option/{idProductOption}
 */
final class TizoMapper
{
    public static function map(
        array $p,
        string $slug,
        string $baseUrl,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        // El endpoint de CATÁLOGO usa idProduct/name/firstImage/availability; el de
        // DETALLE usa productId/productName/filePath/statusProduct. Toleramos ambos.
        $pid = $p['productId'] ?? $p['idProduct'] ?? null;
        if ($pid === null) {
            return null;
        }

        // Precio efectivo = offerPrice; si price es mayor, price es el "de lista".
        $price = isset($p['price']) ? (float) $p['price'] : null;
        $offer = isset($p['offerPrice']) && $p['offerPrice'] !== null ? (float) $p['offerPrice'] : null;
        $selling = $offer !== null && $offer > 0 ? $offer : $price;
        if ($selling === null || $selling <= 0) {
            return null;
        }
        $listPrice = ($price !== null && $price > $selling) ? $price : null;

        // Título: productName/name; si viene vacío, arma "marca + categoría".
        $name  = trim((string) ($p['productName'] ?? $p['name'] ?? ''));
        $brand = trim((string) ($p['brand'] ?? ''));
        if ($name === '') {
            $cat  = trim((string) ($p['motherCategory'] ?? $p['category'] ?? ''));
            $name = trim($brand . ' ' . $cat) ?: ('Producto ' . $pid);
        }

        // Stock: availability (cantidad, catálogo) o statusProduct (detalle).
        $inStock = true;
        if (isset($p['availability']) && is_numeric($p['availability'])) {
            $inStock = (int) $p['availability'] > 0;
        } elseif (isset($p['statusProduct'])) {
            $inStock = (string) $p['statusProduct'] === 'Aprobado';
        }

        $image = $p['filePath'] ?? $p['firstImage'] ?? null;
        $opt   = $p['idProductOption'] ?? '';
        $url   = rtrim($baseUrl, '/') . '/home/product/' . $pid . ($opt !== '' ? '/option/' . $opt : '');

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         (string) $pid,
            url:         $url,
            title:       $name,
            brand:       $brand !== '' ? $brand : null,
            imageUrl:    !empty($image) ? (string) $image : null,
            priceNative: $selling,
            currency:    $currency,
            inStock:     $inStock,
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $listPrice,
            // Vendedor del marketplace: 'store' (catálogo) o 'storeName' (detalle).
            seller:      !empty($p['storeName']) ? (string) $p['storeName']
                          : (!empty($p['store']) ? (string) $p['store'] : null),
        );
    }
}
