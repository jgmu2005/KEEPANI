<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Recorre el catálogo de una tienda VTEX por páginas usando el API:
 *   GET {base}/api/catalog_system/pub/products/search?_from={f}&_to={t}
 *
 * Cada item del catálogo ya trae precio/stock (commertialOffer), así que una
 * llamada = ~50 productos listos para ingerir, sin pedir producto por producto.
 *
 * NOTA: VTEX limita el offset de este endpoint (~2500). Para pasar de ahí hay
 * que recorrer por categorías (mejora futura). Para un SEED inicial, 2500
 * productos por tienda es más que suficiente.
 */
final class VtexCatalogCrawler
{
    /** Tope de items por página que acepta VTEX. */
    public const PAGE_SIZE = 50;
    /** Offset máximo antes de que VTEX corte la paginación plana. */
    public const MAX_OFFSET = 2450;

    public function __construct(
        private Http   $http,
        private string $slug,
        private string $baseUrl,
        private string $currency = 'NIO',
        private bool   $taxIncluded = true,
        private float  $taxRate = 0.15
    ) {}

    /** Construye desde una fila de la tabla stores. */
    public static function fromStore(array $store, Http $http): self
    {
        return new self(
            http: $http,
            slug: (string) $store['slug'],
            baseUrl: (string) $store['base_url'],
            currency: (string) ($store['currency'] ?? 'NIO'),
            taxIncluded: (bool) ($store['tax_included'] ?? 1),
            taxRate: (float) ($store['tax_rate'] ?? 0.15),
        );
    }

    /**
     * Trae una página del catálogo desde $from.
     * @return array<int,array> registros normalizados (toArray) listos para ingerir.
     */
    public function page(int $from, int $count = self::PAGE_SIZE): array
    {
        $to  = $from + $count - 1;
        $url = rtrim($this->baseUrl, '/')
            . '/api/catalog_system/pub/products/search?_from=' . $from . '&_to=' . $to;

        $data = $this->http->getJson($url);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $p) {
            if (!is_array($p)) {
                continue;
            }
            $rec = VtexMapper::map($p, $this->slug, $this->currency, $this->taxIncluded, $this->taxRate);
            if ($rec !== null) {
                $out[] = $rec->toArray();
            }
        }
        return $out;
    }
}
