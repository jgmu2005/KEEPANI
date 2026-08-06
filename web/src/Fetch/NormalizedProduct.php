<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Resultado normalizado que TODO adaptador produce. Es la "interfaz de datos"
 * que consume IngestService, sin importar de qué tienda ni plataforma venga.
 *
 * Reglas de moneda / impuesto:
 *  - priceNative  = precio EXACTO como lo publica la tienda (fuente de verdad).
 *  - taxIncluded  = si ese precio ya trae IVA (la mayoría en NI sí; PriceSmart NO).
 *  - priceFinal() = precio comparable CON IVA (native + IVA si no lo incluye).
 *  La conversión a USD por fecha se hace aparte con exchange_rates.
 */
final class NormalizedProduct
{
    public function __construct(
        public string  $storeSlug,
        public string  $sku,
        public string  $url,
        public ?string $title,
        public ?string $brand,
        public ?string $imageUrl,
        public ?float  $priceNative,
        public string  $currency,
        public bool    $inStock,
        public bool    $taxIncluded,
        public float   $taxRate,
        public ?float  $listPrice = null,
        public string  $capturedAt = '',
        public ?int    $categoryId = null,
    ) {
        if ($this->capturedAt === '') {
            $this->capturedAt = gmdate('c');
        }
    }

    /** Precio comparable CON IVA (lo que un cliente realmente paga). */
    public function priceFinal(): ?float
    {
        if ($this->priceNative === null) {
            return null;
        }
        $p = $this->taxIncluded
            ? $this->priceNative
            : $this->priceNative * (1 + $this->taxRate);
        return round($p, 2);
    }

    /** % de descuento vs listPrice, si aplica. */
    public function discountPct(): ?float
    {
        if ($this->priceNative === null || $this->listPrice === null || $this->listPrice <= 0) {
            return null;
        }
        if ($this->listPrice <= $this->priceNative) {
            return null;
        }
        return round((1 - $this->priceNative / $this->listPrice) * 100, 1);
    }

    /** Payload plano que consume IngestService. */
    public function toArray(): array
    {
        return [
            'store'        => $this->storeSlug,
            'sku'          => $this->sku,
            'url'          => $this->url,
            'title'        => $this->title,
            'brand'        => $this->brand,
            'image_url'    => $this->imageUrl,
            'price_native' => $this->priceNative,
            'price_final'  => $this->priceFinal(),
            'currency'     => $this->currency,
            'in_stock'     => $this->inStock,
            'tax_included' => $this->taxIncluded,
            'tax_rate'     => $this->taxRate,
            'list_price'   => $this->listPrice,
            'discount_pct' => $this->discountPct(),
            'captured_at'  => $this->capturedAt,
            'category_id'  => $this->categoryId,
        ];
    }
}
