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
        $price = (float) $priceRaw;

        // Robustez: algunas tiendas (La Curacao) a veces sirven el OG meta con el
        // precio REGULAR en vez del de promo, generando picos falsos. El JSON-LD
        // schema.org (Offer.price) es la fuente estructurada del precio de VENTA;
        // tomamos el MENOR entre ambos (el que paga el cliente es el de promo).
        $ldPrice = $this->jsonLdOfferPrice($html);
        // Sólo corrige HACIA ABAJO y dentro de una banda razonable (≥25% del OG):
        // así arregla el "precio regular" mal leído (promo real) sin agarrar cuotas
        // ni accesorios sueltos que serían mucho más baratos.
        if ($ldPrice !== null && $ldPrice < $price && $ldPrice >= $price * 0.25) {
            $price = $ldPrice;
        }

        $availRaw = strtolower((string) $this->meta($html, 'product:availability'));
        if ($availRaw !== '') {
            // Copasa / Gallo: la disponibilidad viene en el OG.
            $inStock = str_contains($availRaw, 'in stock')
                || str_contains($availRaw, 'instock')
                || str_contains($availRaw, 'en stock');
        } elseif (preg_match('/class="[^"]*stock\s+unavailable/i', $html)) {
            // Unicomer (Magento sin OG availability): clase de stock del HTML.
            $inStock = false;
        } elseif (preg_match('/class="[^"]*stock\s+available/i', $html)) {
            $inStock = true;
        } else {
            $inStock = true; // sin ninguna señal → asumir disponible
        }

        // Precio de lista (Magento: data-price-amount; el mayor > precio = precio tachado).
        $list = null;
        if (preg_match_all('/data-price-amount="([0-9.]+)"/', $html, $mm)) {
            $max = max(array_map('floatval', $mm[1]));
            if ($max > $price) {
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
            priceNative: $price,
            currency:    $this->meta($html, 'product:price:currency') ?: $this->currencyFallback,
            inStock:     $inStock,
            taxIncluded: $this->taxIncluded,
            taxRate:     $this->taxRate,
            listPrice:   $list,
        );
    }

    /**
     * Precio de venta desde el JSON-LD schema.org (Offer/AggregateOffer). Devuelve
     * el MENOR "price"/"lowPrice" hallado dentro de los bloques <script ld+json>
     * (el precio de promo), o null si no hay. Sólo mira esos bloques estructurados,
     * no números sueltos del HTML, para no agarrar cuotas ni productos relacionados.
     */
    private function jsonLdOfferPrice(string $html): ?float
    {
        if (!preg_match_all('/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $blocks)) {
            return null;
        }
        $best = null;
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/"(?:low[Pp]rice|price)"\s*:\s*"?([0-9]+(?:\.[0-9]+)?)"?/', $block, $pm)) {
                foreach ($pm[1] as $v) {
                    $f = (float) $v;
                    if ($f > 0 && ($best === null || $f < $best)) {
                        $best = $f;
                    }
                }
            }
        }
        return $best;
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
