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

        // EAN/GTIN: VTEX lo pone por item; puede venir vacío. Tomamos el primero no vacío.
        $ean = null;
        foreach ($p['items'] ?? [] as $it) {
            if (!empty($it['ean'])) {
                $ean = (string) $it['ean'];
                break;
            }
        }

        // refIds de TODOS los SKUs (+ productReference): son los números que aparecen
        // en las URLs / la "Referencia" de la ficha. Los guardamos para resolver el
        // producto por cualquiera de ellos (un producto con tallas tiene varios).
        $refIds = [];
        if (!empty($p['productReference'])) {
            $refIds[] = (string) $p['productReference'];
        }
        foreach ($p['items'] ?? [] as $it) {
            foreach ($it['referenceId'] ?? [] as $r) {
                if (isset($r['Value']) && $r['Value'] !== '') {
                    $refIds[] = (string) $r['Value'];
                }
            }
        }
        $refIds = array_values(array_unique($refIds));

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
            categoryId:  isset($p['categoryId']) ? (int) $p['categoryId'] : null,
            ean:         $ean,
            refIds:      $refIds,
        );
    }
}
