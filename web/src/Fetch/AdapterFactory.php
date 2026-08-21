<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Construye el adaptador correcto a partir de una fila de la tabla `stores`.
 * Así el runner se maneja 100% desde la base de datos (no de un archivo fijo).
 */
final class AdapterFactory
{
    public static function fromStore(array $store, Http $http): StoreAdapter
    {
        $slug        = (string) $store['slug'];
        $baseUrl     = (string) $store['base_url'];
        $currency    = (string) ($store['currency'] ?? 'NIO');
        $taxIncluded = (bool) ($store['tax_included'] ?? 1);
        $taxRate     = (float) ($store['tax_rate'] ?? 0.15);

        return match ($store['platform']) {
            'vtex' => new VtexAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                currency: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            'og_meta' => new OgMetaAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                productPath: $store['product_path'] ?? '/Product/Detail/{sku}',
                currencyFallback: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            'bloomreach' => new PriceSmartAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                currency: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            'shopify' => new ShopifyAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                currency: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            'tizo' => new TizoAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                currency: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            'woocommerce' => new WooAdapter(
                http: $http, slug: $slug, baseUrl: $baseUrl,
                currency: $currency, taxIncluded: $taxIncluded, taxRate: $taxRate,
            ),
            default => throw new \InvalidArgumentException(
                "Plataforma sin adaptador implementado: {$store['platform']} ({$slug})"
            ),
        };
    }
}
