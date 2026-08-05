<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte UN producto del JSON del catálogo VTEX en NormalizedProduct.
 * Lo usan por igual el VtexAdapter (1 producto) y el VtexCatalogCrawler (páginas),
 * así la extracción de precio/stock vive en un solo lugar.
 */
final class VtexMapper
{
    public static function map(
        array $p,
        string $slug,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        $item = $p['items'][0] ?? null;
        if (!$item) {
            return null;
        }

        // Seller por defecto (o el primero).
        $offer = $item['sellers'][0]['commertialOffer'] ?? null;
        foreach ($item['sellers'] ?? [] as $s) {
            if (!empty($s['sellerDefault'])) {
                $offer = $s['commertialOffer'];
                break;
            }
        }

        $price     = isset($offer['Price']) ? (float) $offer['Price'] : null;
        $listPrice = isset($offer['ListPrice']) ? (float) $offer['ListPrice'] : null;
        $avail     = (int) ($offer['AvailableQuantity'] ?? 0);
        $isAvail   = (bool) ($offer['IsAvailable'] ?? false);

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         (string) $p['productId'],
            url:         $p['link'] ?? '',
            title:       $p['productName'] ?? null,
            brand:       $p['brand'] ?? null,
            imageUrl:    $item['images'][0]['imageUrl'] ?? null,
            priceNative: $price,
            currency:    $currency,
            inStock:     $isAvail && $avail > 0,
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $listPrice,
        );
    }
}
