<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Convierte un producto del feed HCL/wcaas de Claro en NormalizedProduct.
 *
 * Estructura: producto → locales.es_ni.name/seo, items[].pricing.usd.offer_price.
 * OJO: pese al label "usd", offer_price viene en CENTAVOS DE CÓRDOBA
 * (7399599 = C$73 995.99, el "precio de contado" que muestra el sitio).
 */
final class ClaroMapper
{
    private const CDN = 'https://tiendaenlinea.claro.com.ni/cdn';

    public static function map(
        array $p,
        string $slug,
        string $baseUrl,
        string $currency,
        bool $taxIncluded,
        float $taxRate
    ): ?NormalizedProduct {
        $sku  = (string) ($p['part_number'] ?? '');
        $loc  = $p['locales']['es_ni'] ?? [];
        $name = $loc['name'] ?? null;
        if ($sku === '' || $name === null) {
            return null;
        }

        // Primer item con precio de contado.
        $price = null; $list = null; $image = null;
        foreach ($p['items'] ?? [] as $it) {
            $off = $it['pricing']['usd']['offer_price'] ?? null;
            if ($off === null) {
                continue;
            }
            $price = (float) $off / 100;
            $lp = $it['pricing']['usd']['list_price'] ?? null;
            if ($lp !== null && (float) $lp / 100 > $price) {
                $list = (float) $lp / 100;
            }
            $imgs = $it['images'] ?? [];
            if (!empty($imgs[0])) {
                $image = self::CDN . $imgs[0];
            }
            break;
        }
        if ($price === null) {
            return null;
        }

        $seoName = (string) ($loc['seo']['name'] ?? '');
        $url = rtrim($baseUrl, '/') . '/personas/tiendaenlinea/products/' . $seoName;

        return new NormalizedProduct(
            storeSlug:   $slug,
            sku:         $sku,
            url:         $url,
            title:       $name,
            brand:       self::detectBrand((string) $name),
            imageUrl:    $image,
            priceNative: $price,
            currency:    $currency,
            inStock:     !empty($p['available']) && !empty($p['buyable']),
            taxIncluded: $taxIncluded,
            taxRate:     $taxRate,
            listPrice:   $list,
        );
    }

    /** Marca a partir del nombre (para el blocking del matcher). */
    private static function detectBrand(string $name): ?string
    {
        $n = mb_strtolower($name, 'UTF-8');
        $map = [
            'iphone' => 'Apple', 'apple' => 'Apple', 'ipad' => 'Apple',
            'samsung' => 'Samsung', 'galaxy' => 'Samsung',
            'honor' => 'Honor', 'huawei' => 'Huawei',
            'xiaomi' => 'Xiaomi', 'redmi' => 'Xiaomi', 'poco' => 'Xiaomi',
            'motorola' => 'Motorola', 'moto ' => 'Motorola',
            'tecno' => 'Tecno', 'infinix' => 'Infinix', 'itel' => 'itel',
            'nokia' => 'Nokia', 'oppo' => 'Oppo', 'realme' => 'Realme',
            'zte' => 'ZTE', 'alcatel' => 'Alcatel',
        ];
        foreach ($map as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }
        return null;
    }
}
