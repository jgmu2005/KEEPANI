<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte un "doc" de Bloomreach Discovery (PriceSmart) en NormalizedProduct.
 * Precios vienen en CENTAVOS (price_NI:3699995 = C$36 999.95, fractionDigits=2).
 */
final class PriceSmartMapper
{
    public static function map(
        array $doc,
        string $slug,
        string $baseUrl,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        $pid = (string) ($doc['pid'] ?? $doc['master_sku'] ?? '');
        if ($pid === '' || !isset($doc['price_NI'])) {
            return null;
        }

        $price = (float) $doc['price_NI'] / 100;
        $listRaw = isset($doc['original_price_without_saving_NI']) ? (float) $doc['original_price_without_saving_NI'] / 100 : null;
        $list = ($listRaw !== null && $listRaw > $price) ? $listRaw : null;

        $prodSlug = (string) ($doc['slug'] ?? '');
        $url = rtrim($baseUrl, '/') . '/es-ni/producto/' . $prodSlug . '/' . $pid;

        $inv   = strtolower((string) ($doc['inventory_NI'] ?? ''));
        $avail = strtolower((string) ($doc['availability_NI'] ?? ''));
        $inStock = str_contains($inv, 'in stock') && $avail !== 'false';

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         $pid,
            url:         $url,
            title:       $doc['title'] ?? null,
            brand:       $doc['brand'] ?? null,
            imageUrl:    $doc['thumb_image'] ?? null,
            priceNative: $price,
            currency:    $doc['currency'] ?? $currency,
            inStock:     $inStock,
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $list,
        );
    }
}
