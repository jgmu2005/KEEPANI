<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Consultas de LECTURA para el dashboard público:
 *  - resolver un producto por id / (tienda+sku) / URL pegada
 *  - traer su ficha + histórico de precios
 *  - listar los productos rastreados con su último precio
 */
final class ProductRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Resuelve el product_id a partir de lo que el usuario tenga a mano.
     * Soporta pegar la URL del producto (extrae tienda+sku según la plataforma).
     */
    public function resolve(?int $id, ?string $store, ?string $sku, ?string $url): ?int
    {
        if ($id) {
            $st = $this->db->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
            $st->execute([$id]);
            $found = $st->fetchColumn();
            return $found !== false ? (int) $found : null;
        }

        if ($store && $sku) {
            return $this->bySlugSku($store, $sku);
        }

        if ($url) {
            // 1) match directo por URL guardada.
            $st = $this->db->prepare('SELECT id FROM products WHERE url = ? LIMIT 1');
            $st->execute([$url]);
            $found = $st->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
            // 2) extraer tienda (por dominio) + sku (por plataforma) y buscar.
            [$slug, $extractedSku] = $this->parseUrl($url);
            if ($slug && $extractedSku) {
                $byRef = $this->bySlugSku($slug, $extractedSku);
                if ($byRef !== null) {
                    return $byRef;
                }
                // 3) VTEX: la URL lleva el refId de UN SKU (talla/color), pero nosotros
                //    guardamos el producto por su productId. Si la ficha muestra un SKU
                //    distinto al que crawleamos, (1) y (2) fallan aunque SÍ lo rastreemos.
                //    3a) Alias LOCAL refId→producto (poblado en cada ingesta): rápido y confiable.
                $byAlias = $this->bySlugRefId($slug, $extractedSku);
                if ($byAlias !== null) {
                    return $byAlias;
                }
                // 3b) Puente EN VIVO refId→productId contra la API de la tienda. Sólo como
                //     respaldo (mientras el crawl no pobló el alias, u on-demand); es una
                //     llamada externa que puede fallar, por eso va de último.
                $pid = $this->vtexProductIdByRef($slug, $extractedSku);
                if ($pid !== null && $pid !== $extractedSku) {
                    $byPid = $this->bySlugSku($slug, $pid);
                    if ($byPid !== null) {
                        return $byPid;
                    }
                }
            }
        }

        return null;
    }

    /** Fila de la tienda por slug (para construir su adaptador). */
    public function storeBySlug(string $slug): ?array
    {
        $st = $this->db->prepare('SELECT * FROM stores WHERE slug = ? AND is_active = 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Deduce [slug_tienda, sku] de lo que el usuario pegó, AUNQUE el producto
     * todavía no esté rastreado. Lo usa track.php para agregarlo en vivo.
     * Devuelve ['slug'=>..., 'sku'=>...] o null si no reconoce la tienda/URL.
     */
    public function locate(?string $store, ?string $sku, ?string $url): ?array
    {
        if ($store && $sku) {
            return ['slug' => $store, 'sku' => $sku];
        }
        if ($url) {
            [$slug, $extractedSku] = $this->parseUrl($url);
            if ($slug && $extractedSku) {
                return ['slug' => $slug, 'sku' => $extractedSku];
            }
        }
        return null;
    }

    private function bySlugSku(string $slug, string $sku): ?int
    {
        $st = $this->db->prepare(
            'SELECT p.id
               FROM products p
               JOIN stores s ON s.id = p.store_id
              WHERE s.slug = ? AND p.external_sku = ? AND p.is_active = 1
              LIMIT 1'
        );
        $st->execute([$slug, $sku]);
        $found = $st->fetchColumn();
        return $found !== false ? (int) $found : null;
    }

    /**
     * Resuelve por refId de SKU usando el mapa local product_skus (poblado en cada
     * ingesta desde VtexMapper). Es el camino rápido/confiable para productos VTEX
     * con tallas/colores, cuya URL trae un refId distinto al productId guardado.
     * Best-effort: si la tabla no existe todavía, devuelve null sin romper.
     */
    private function bySlugRefId(string $slug, string $refId): ?int
    {
        try {
            $st = $this->db->prepare(
                'SELECT ps.product_id
                   FROM product_skus ps
                   JOIN stores   s ON s.id = ps.store_id
                   JOIN products p ON p.id = ps.product_id
                  WHERE s.slug = ? AND ps.ref_id = ? AND p.is_active = 1
                  LIMIT 1'
            );
            $st->execute([$slug, $refId]);
            $found = $st->fetchColumn();
            return $found !== false ? (int) $found : null;
        } catch (\Throwable $e) {
            return null; // product_skus aún no migrada
        }
    }

    /**
     * VTEX: mapea el refId de un SKU (el número de la URL / la "Referencia" que
     * muestra la ficha) al productId canónico, que es lo que guardamos en
     * external_sku. Un producto con tallas/colores tiene varios refId pero un solo
     * productId; la página puede mostrar cualquiera de ellos. Devuelve null si la
     * tienda no es VTEX, no responde, o no hay match. Timeout corto: es un fallback
     * en vivo dentro de la resolución, sólo cuando el match local ya falló.
     */
    private function vtexProductIdByRef(string $slug, string $refId): ?string
    {
        $store = $this->storeBySlug($slug);
        if (!$store || (($store['platform'] ?? '') !== 'vtex')) {
            return null;
        }
        $base = rtrim((string) $store['base_url'], '/');
        $api  = $base . '/api/catalog_system/pub/products/search?fq=alternateIds_RefId:' . rawurlencode($refId);

        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; OjoAlPrecio/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $j = json_decode((string) $body, true);
        if (!is_array($j) || !isset($j[0]['productId'])) {
            return null;
        }
        return (string) $j[0]['productId'];
    }

    /** De una URL de producto deduce [slug_tienda, sku] según la plataforma. */
    private function parseUrl(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return [null, null];
        }

        // Empareja el host con la base_url de alguna tienda.
        $store = null;
        foreach ($this->db->query('SELECT slug, base_url, platform FROM stores') as $s) {
            $sHost = strtolower((string) parse_url($s['base_url'], PHP_URL_HOST));
            $sHostBare = preg_replace('/^www\./', '', $sHost);
            $hostBare  = preg_replace('/^www\./', '', $host);
            if ($hostBare === $sHostBare) {
                $store = $s;
                break;
            }
        }
        if (!$store) {
            return [null, null];
        }

        // Delimitador ~ (no # ) porque el patrón contiene '#' dentro de [?#].
        $sku = match ($store['platform']) {
            // VTEX: .../slug-del-producto-{ref-o-productId}/p — el id debe tener ≥6
            // dígitos (los de Sinsa/Siman tienen 9). Walmart usa URLs SOLO-slug sin id
            // canónico, así que un token corto (…-163cm-7/p → "7") NO cuenta: devuelve
            // null y el producto no rastreado se muestra como tal (evita colisiones).
            'vtex'    => (preg_match('~-(\d{6,})(?:/p)?/?(?:[?#].*)?$~', $url, $m) ? $m[1] : null),
            // OG: Copasa (.../Product/Detail/{sku}), Gallo (.../slug-{id}) o Unicomer (.../slug-{id}/p)
            'og_meta' => (
                preg_match('~/Product/Detail/([^/?#]+)~i', $url, $m) ? rawurldecode($m[1])          // Copasa
                    : (preg_match('~/product/([^/?#]+)~', $url, $m3) ? rawurldecode($m3[1])          // Comtech: /product/{code}
                    : (preg_match('~-(\d+)(?:/p)?/?(?:[?#].*)?$~', $url, $m2) ? $m2[1] : null))       // Gallo/Unicomer: slug-{id}
            ),
            // PriceSmart: .../es-ni/producto/{slug}-{pid}/{pid}-{ean} → el pid es el
            // número al inicio del último segmento (antes del guion, si lo hay).
            'bloomreach' => (preg_match('~/(\d+)(?:-\w+)?/?(?:[?#].*)?$~', $url, $m) ? $m[1] : null),
            // Shopify: .../products/{handle}[?variant=...] → el handle es el sku.
            'shopify' => (preg_match('~/products/([^/?#]+)~', $url, $m) ? rawurldecode($m[1]) : null),
            // WooCommerce: .../product/{slug} o .../producto/{slug} → el slug es el sku.
            'woocommerce' => (preg_match('~/(?:products?|productos?)/([^/?#]+)~', $url, $m) ? rawurldecode($m[1]) : null),
            // Tizo: .../home/product/{productId}/option/{opt} → el id es el sku.
            'tizo' => (preg_match('~/home/product/(\d+)~', $url, $m) ? $m[1] : null),
            // Magento (Samsung): .../latin/{cc}/{url_key}.html → el url_key es el sku
            // (excluye las de categoría .../shop/... que llevan una barra extra).
            'magento' => (preg_match('~/latin/[a-z]{2}/([^/?#]+)\.html(?:[?#]|$)~i', $url, $m) ? rawurldecode($m[1]) : null),
            default   => null,
        };

        return [$store['slug'], $sku];
    }

    /** Ficha del producto + datos de su tienda. */
    public function product(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.id, p.external_sku AS sku, p.title, p.brand, p.seller, p.image_url, p.url,
                    s.slug AS store, s.name AS store_name, s.currency, s.tax_included,
                    g.slug AS group_slug, g.store_count AS group_stores
               FROM products p
               JOIN stores s ON s.id = p.store_id
               LEFT JOIN product_groups g ON g.id = p.group_id
              WHERE p.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $row['group_stores'] = $row['group_stores'] !== null ? (int) $row['group_stores'] : null;
        $row['tax_added']    = !(bool) $row['tax_included'];
        return $row;
    }

    /** Histórico ordenado por fecha (para la gráfica). */
    public function history(int $id): array
    {
        $st = $this->db->prepare(
            'SELECT captured_date AS date, price_final, price_native, list_price,
                    discount_pct, currency, in_stock
               FROM price_history
              WHERE product_id = ?
              ORDER BY captured_date ASC'
        );
        $st->execute([$id]);
        $rows = $st->fetchAll();

        // Tipa los números para que el JSON salga limpio.
        return array_map(static function (array $r): array {
            return [
                'date'         => $r['date'],
                'price_final'  => $r['price_final'] !== null ? (float) $r['price_final'] : null,
                'price_native' => $r['price_native'] !== null ? (float) $r['price_native'] : null,
                'list_price'   => $r['list_price'] !== null ? (float) $r['list_price'] : null,
                'discount_pct' => $r['discount_pct'] !== null ? (float) $r['discount_pct'] : null,
                'currency'     => $r['currency'],
                'in_stock'     => (bool) $r['in_stock'],
            ];
        }, $rows);
    }

    /** Estadísticas rápidas sobre el histórico. */
    public function stats(array $history): array
    {
        $prices = array_values(array_filter(array_map(
            static fn($h) => $h['price_final'],
            $history
        ), static fn($v) => $v !== null));

        $last = end($history) ?: null;

        $min = $prices ? min($prices) : null;
        $max = $prices ? max($prices) : null;

        // "Máximo histórico" ROBUSTO: descarta picos exagerados (lecturas del precio
        // regular u otros errores puntuales) usando la mediana como referencia — el
        // mismo criterio que el "habitual". Un pico aislado NO mueve la mediana, así
        // que se excluye; un aumento SOSTENIDO sí la mueve, y entonces SÍ cuenta como
        // máximo real. Se conserva el crudo en max_raw por si se necesita.
        $maxReal = $max;
        if (count($prices) >= self::ROBUST_MIN_POINTS) {
            $median = self::medianOf($prices);
            if ($median > 0) {
                $normal = array_filter($prices, static fn($p) => $p <= $median * self::ROBUST_MAX_FACTOR);
                if ($normal) {
                    $maxReal = max($normal);
                }
            }
        }

        return [
            'current'   => $last['price_final'] ?? null,
            'min'       => $min,
            'max'       => $maxReal,
            'max_raw'   => $max,
            'in_stock'  => $last['in_stock'] ?? false,
            'currency'  => $last['currency'] ?? 'NIO',
            'points'    => count($history),
            'last_date' => $last['date'] ?? null,
        ];
    }

    /** Umbral de puntos y factor (× mediana) para el máximo robusto. */
    private const ROBUST_MIN_POINTS = 5;
    private const ROBUST_MAX_FACTOR = 1.4;

    private static function medianOf(array $a): float
    {
        sort($a);
        $n = count($a);
        $m = intdiv($n, 2);
        return $n % 2 ? (float) $a[$m] : ((float) $a[$m - 1] + (float) $a[$m]) / 2;
    }

    /** Tiendas activas (para el dropdown de filtro). */
    public function activeStores(): array
    {
        return array_map(
            static fn(array $r): array => ['slug' => $r['slug'], 'name' => $r['name']],
            $this->db->query('SELECT slug, name FROM stores WHERE is_active = 1 ORDER BY name')->fetchAll()
        );
    }

    /**
     * Buckets de categoría cross-store CON productos (para los chips del catálogo).
     * Solo cuenta productos con stock. Devuelve [{key,label,count}] en el orden
     * de CategoryClassifier::LABELS.
     */
    public function categoryBuckets(): array
    {
        $rows = $this->db->query(
            'SELECT p.cat_key AS k, COUNT(*) AS n
               FROM products p
              WHERE p.is_active = 1 AND p.cat_key IS NOT NULL AND p.last_in_stock = 1
              GROUP BY p.cat_key'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $out = [];
        foreach (CategoryClassifier::LABELS as $key => $label) {
            $n = (int) ($rows[$key] ?? 0);
            if ($n > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'count' => $n];
            }
        }
        return $out;
    }

    /**
     * Conteo de TVs por rango de tamaño (para los sub-chips de "Televisores").
     * Solo TVs con stock. @return array<int,array{key,label,count}> (los que tienen ≥1).
     */
    public function tvSizeBuckets(): array
    {
        $row = $this->db->query(
            "SELECT
                SUM(p.tv_inches <= 31)              AS s,
                SUM(p.tv_inches BETWEEN 32 AND 40)  AS m,
                SUM(p.tv_inches BETWEEN 41 AND 55)  AS l,
                SUM(p.tv_inches >= 56)              AS xl,
                SUM(p.tv_inches IS NULL)            AS na
              FROM products p
             WHERE p.is_active = 1 AND p.cat_key = 'tv' AND p.last_in_stock = 1"
        )->fetch() ?: [];

        $labels = [
            's'  => '📏 31\" o menos',
            'm'  => '32–40\"',
            'l'  => '41–55\"',
            'xl' => '56\"+',
            'na' => 'Otros',
        ];
        $out = [];
        foreach ($labels as $k => $label) {
            $n = (int) ($row[$k] ?? 0);
            if ($n > 0) { $out[] = ['key' => $k, 'label' => $label, 'count' => $n]; }
        }
        return $out;
    }

    /** Categorías de una tienda que tienen al menos un producto (para el filtro). */
    public function categoriesWithProducts(string $storeSlug): array
    {
        $sql = 'SELECT c.external_id AS id, c.name
                  FROM categories c
                  JOIN stores s ON s.id = c.store_id
                 WHERE s.slug = ?
                   AND EXISTS (
                        SELECT 1 FROM products p
                         WHERE p.store_id = c.store_id
                           AND p.category_external_id = c.external_id
                           AND p.is_active = 1)
                 ORDER BY c.name';
        $st = $this->db->prepare($sql);
        $st->execute([$storeSlug]);
        return array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
            $st->fetchAll()
        );
    }

    /**
     * Búsqueda/filtrado COMBINADO de productos rastreados (todo junto):
     *   q (nombre), store (slug), min/max (precio final), sort, limit, offset.
     * Devuelve ['total' => int, 'items' => array] — total respeta los filtros.
     */
    public function search(array $f): array
    {
        $where  = ['p.is_active = 1'];
        $params = [];

        if (!empty($f['q'])) {
            // Palabras sueltas, cualquier orden: "cubitt audifono" matchea
            // "Audífono Cubitt …". Todas las palabras deben aparecer en el título.
            [$qCond, $qParams] = SearchQuery::like((string) $f['q'], ['p.title']);
            if ($qCond !== '') {
                $where[] = $qCond;
                $params += $qParams;
            }
        }
        if (!empty($f['store'])) {
            $where[] = 's.slug = :store';
            $params[':store'] = $f['store'];
        }
        if (isset($f['min']) && $f['min'] !== '' && $f['min'] !== null) {
            $where[] = 'p.last_price >= :min';
            $params[':min'] = (float) $f['min'];
        }
        if (isset($f['max']) && $f['max'] !== '' && $f['max'] !== null) {
            $where[] = 'p.last_price <= :max';
            $params[':max'] = (float) $f['max'];
        }
        if (!empty($f['in_stock'])) {
            $where[] = 'p.last_in_stock = 1';
        }
        if (!empty($f['category'])) {
            $where[] = 'p.category_external_id = :cat';
            $params[':cat'] = (int) $f['category'];
        }
        if (!empty($f['cat_key'])) {
            $where[] = 'p.cat_key = :catkey';
            $params[':catkey'] = (string) $f['cat_key'];
        }
        // Filtro de tamaño de TV (pulgadas). 'na' = sin medida en el título.
        $tvRange = [
            's'  => 'p.tv_inches <= 31',
            'm'  => 'p.tv_inches BETWEEN 32 AND 40',
            'l'  => 'p.tv_inches BETWEEN 41 AND 55',
            'xl' => 'p.tv_inches >= 56',
            'na' => 'p.tv_inches IS NULL',
        ];
        if (!empty($f['tv_size']) && isset($tvRange[$f['tv_size']])) {
            $where[] = $tvRange[$f['tv_size']];
        }
        $whereSql = implode(' AND ', $where);

        // Orden: lista blanca (nunca interpolar entrada del usuario).
        $sortMap = [
            'name'       => 'p.title ASC',
            'price_asc'  => 'p.last_price ASC',
            'price_desc' => 'p.last_price DESC',
            'discount'   => '((p.last_list - p.last_price) / NULLIF(p.last_list, 0)) DESC',
        ];
        $orderSql = $sortMap[$f['sort'] ?? 'name'] ?? $sortMap['name'];

        $limit  = max(1, min((int) ($f['limit'] ?? 50), 10000));
        $offset = max(0, (int) ($f['offset'] ?? 0));

        // FROM + WHERE compartido entre el conteo y la página.
        $base = 'FROM products p
                 JOIN stores s ON s.id = p.store_id
                 LEFT JOIN product_groups pg ON pg.id = p.group_id
                 WHERE ' . $whereSql;

        $countStmt = $this->db->prepare('SELECT COUNT(*) ' . $base);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name, s.tax_included,
                       p.last_price AS price_final, p.last_list AS list_price,
                       p.last_currency AS currency, p.last_in_stock AS in_stock, p.last_date AS last_date,
                       pg.slug AS group_slug, pg.store_count AS group_stores
                ' . $base . '
                ORDER BY ' . $orderSql . '
                LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $items = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'title'        => $r['title'],
                'brand'        => $r['brand'],
                'image_url'    => $r['image_url'],
                'url'          => $r['url'],
                'store'        => $r['store'],
                'store_name'   => $r['store_name'],
                'price_final'  => $r['price_final'] !== null ? (float) $r['price_final'] : null,
                'list_price'   => $r['list_price'] !== null ? (float) $r['list_price'] : null,
                // Descuento derivado de lista vs precio (ya no guardamos discount_pct denormalizado).
                'discount_pct' => ($r['list_price'] !== null && $r['price_final'] !== null
                                   && (float) $r['list_price'] > (float) $r['price_final'])
                                  ? round((1 - (float) $r['price_final'] / (float) $r['list_price']) * 100, 1) : null,
                'currency'     => $r['currency'] ?? 'NIO',
                'in_stock'     => (bool) $r['in_stock'],
                'tax_added'    => !(bool) $r['tax_included'],
                'last_date'    => $r['last_date'],
                'group_slug'   => $r['group_slug'] ?? null,
                'group_stores' => $r['group_stores'] !== null ? (int) $r['group_stores'] : null,
            ];
        }, $stmt->fetchAll());

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Serie de precios (últimos N días) para un conjunto de productos, en UNA
     * sola query — alimenta los sparklines de las tarjetas.
     * Devuelve [product_id => [['d' => 'YYYY-MM-DD', 'p' => float], ...]] por fecha.
     */
    public function priceSeries(array $ids, int $days = 45): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0));
        if (!$ids) {
            return [];
        }
        $days = max(7, min($days, 180));
        $in   = implode(',', $ids); // ints ya saneados: seguro para interpolar
        // Sólo capturas EN STOCK: las agotadas traen precio-centinela (ej. Siman
        // C$10,000,000) que falseaba el mínimo/máximo histórico y los sparklines.
        $sql  = "SELECT product_id, captured_date AS d, price_final AS p
                   FROM price_history
                  WHERE product_id IN ($in)
                    AND price_final IS NOT NULL
                    AND in_stock = 1
                    AND captured_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                  ORDER BY product_id, captured_date";
        $out = [];
        foreach ($this->db->query($sql)->fetchAll() as $r) {
            $out[(int) $r['product_id']][] = ['d' => $r['d'], 'p' => (float) $r['p']];
        }
        return $out;
    }

    /**
     * Grupo del comparador por slug + sus ofertas (una por producto/tienda),
     * ordenadas por precio. Para la página pública producto.php.
     * @return array{group:array,offers:array}|null
     */
    public function groupBySlug(string $slug): ?array
    {
        $gs = $this->db->prepare('SELECT * FROM product_groups WHERE slug = ? LIMIT 1');
        $gs->execute([$slug]);
        $g = $gs->fetch();
        if (!$g) {
            return null;
        }

        $st = $this->db->prepare(
            'SELECT p.id, p.title, p.brand, p.image_url, p.url, p.group_locked, p.seller,
                    s.slug AS store, s.name AS store_name, s.tax_included,
                    p.last_price AS price_final, p.last_list AS list_price,
                    p.last_currency AS currency, p.last_in_stock AS in_stock, p.last_date
               FROM products p
               JOIN stores s ON s.id = p.store_id
              WHERE p.group_id = ? AND p.is_active = 1
              ORDER BY (p.last_price IS NULL), p.last_price ASC'
        );
        $st->execute([(int) $g['id']]);

        $offers = array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'store'       => $r['store'],
                'store_name'  => $r['store_name'],
                'title'       => $r['title'],
                'image_url'   => $r['image_url'],
                'url'         => $r['url'],
                'price_final' => $r['price_final'] !== null ? (float) $r['price_final'] : null,
                'list_price'  => $r['list_price'] !== null ? (float) $r['list_price'] : null,
                'currency'    => $r['currency'] ?? 'NIO',
                'in_stock'    => (bool) $r['in_stock'],
                'tax_added'   => !(bool) $r['tax_included'], // precio con IVA estimado (+15%)
                'last_date'   => $r['last_date'],
                'locked'      => (bool) ($r['group_locked'] ?? false),
                'seller'      => $r['seller'] ?? null,
            ];
        }, $st->fetchAll());

        return [
            'group' => [
                'id'              => (int) $g['id'],
                'slug'            => $g['slug'],
                'canonical_title' => $g['canonical_title'],
                'brand'           => $g['brand'],
                'image_url'       => $g['image_url'],
                'store_count'     => (int) $g['store_count'],
                'member_count'    => (int) $g['member_count'],
            ],
            'offers' => $offers,
        ];
    }

    /**
     * Lista de grupos multi-tienda (para la vitrina "Comparador"), con su rango
     * de precio actual entre tiendas. @return array{total:int, items:array}
     */
    public function groupsList(int $limit = 24, int $offset = 0, string $sort = 'discrepancy', ?string $method = null): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // Filtro opcional por método de agrupamiento ('model' = celulares).
        $where  = 'g.store_count >= 2';
        $params = [];
        if ($method !== null && $method !== '') {
            $where .= ' AND g.method = :method';
            $params[':method'] = $method;
        }

        // Sólo cuentan las ofertas EN STOCK. Precio/stock ACTUAL denormalizado en
        // products.last_* (Fase B): ya no hace falta buscar la última fila en
        // price_history por cada producto. last_price viene saneado (centinela → null).
        $base = 'FROM product_groups g
                  JOIN products p ON p.group_id = g.id AND p.is_active = 1
                 WHERE ' . $where . ' AND p.last_in_stock = 1
                   AND p.last_price IS NOT NULL AND p.last_price < 1000000
                 GROUP BY g.id
                 HAVING COUNT(DISTINCT p.store_id) >= 2';

        $cnt = $this->db->prepare('SELECT COUNT(*) FROM (SELECT g.id ' . $base . ') t');
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        // Orden: por diferencia de precio % entre tiendas (default) o por # de tiendas.
        $order = $sort === 'stores'
            ? 'store_count DESC, g.updated_at DESC'
            : '(MAX(p.last_price) - MIN(p.last_price)) / NULLIF(MIN(p.last_price), 0) DESC, store_count DESC';

        $sql = 'SELECT g.slug, g.canonical_title AS title, g.brand, g.image_url,
                       COUNT(DISTINCT p.store_id) AS store_count,
                       MIN(p.last_price) AS min_price, MAX(p.last_price) AS max_price,
                       MAX(p.last_currency) AS currency
                  ' . $base . '
                 ORDER BY ' . $order . '
                 LIMIT ' . $limit . ' OFFSET ' . $offset;
        $st = $this->db->prepare($sql);
        $st->execute($params);

        $items = array_map(static function (array $r): array {
            return [
                'slug'        => $r['slug'],
                'title'       => Normalizer::cleanDisplayTitle($r['title']),
                'brand'       => $r['brand'],
                'image_url'   => $r['image_url'],
                'store_count' => (int) $r['store_count'],
                'min_price'   => $r['min_price'] !== null ? (float) $r['min_price'] : null,
                'max_price'   => $r['max_price'] !== null ? (float) $r['max_price'] : null,
                'currency'    => $r['currency'] ?? 'NIO',
            ];
        }, $st->fetchAll());

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Productos cuyo precio CAMBIÓ entre su última captura y la anterior.
     * (Se llena cuando hay ≥2 días con precios distintos; antes va vacío.)
     */
    public function recentChanges(int $limit = 12): array
    {
        $limit = max(1, min($limit, 60));
        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name,
                       cur.price_final  AS price_now,
                       prev.price_final AS price_prev,
                       cur.currency, cur.in_stock, cur.captured_date AS date_now
                  FROM products p
                  JOIN stores s ON s.id = p.store_id
                  JOIN price_history cur ON cur.id = (
                        SELECT id FROM price_history WHERE product_id = p.id
                        ORDER BY captured_at DESC LIMIT 1)
                  JOIN price_history prev ON prev.id = (
                        SELECT id FROM price_history WHERE product_id = p.id
                          AND captured_date < cur.captured_date
                        ORDER BY captured_at DESC LIMIT 1)
                 WHERE cur.price_final IS NOT NULL AND prev.price_final IS NOT NULL
                   AND cur.price_final <> prev.price_final
                   AND cur.in_stock = 1
                 ORDER BY cur.captured_at DESC
                 LIMIT ' . $limit;

        return array_map([self::class, 'mapChange'], $this->db->query($sql)->fetchAll());
    }

    /**
     * Todos los cambios de precio, ordenables: 'drop' (mayores bajas) o 'rise'
     * (mayores subas). Con paginación por offset (para "Ver más"). Sin COUNT
     * (se pagina hasta que devuelve menos de $limit).
     */
    public function changesList(string $sort, int $limit = 24, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 60));
        $offset = max(0, $offset);
        $isDrop = $sort !== 'rise';
        $order  = $isDrop ? 'delta ASC' : 'delta DESC';

        // Para BAJAS filtramos las "falsas ofertas" (volver a la normalidad tras un
        // pico) contra la mediana del propio producto, así que traemos un pool más
        // grande y paginamos en PHP tras filtrar. Para SUBAS no hace falta filtrar.
        $poolLimit = $isDrop ? 300 : $limit;
        $poolOff   = $isDrop ? 0 : $offset;

        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name,
                       cur.price_final  AS price_now,
                       prev.price_final AS price_prev,
                       cur.currency, cur.in_stock, cur.captured_date AS date_now,
                       (cur.price_final - prev.price_final) / prev.price_final AS delta
                  FROM products p
                  JOIN stores s ON s.id = p.store_id
                  JOIN price_history cur ON cur.id = (
                        SELECT id FROM price_history WHERE product_id = p.id
                        ORDER BY captured_at DESC LIMIT 1)
                  JOIN price_history prev ON prev.id = (
                        SELECT id FROM price_history WHERE product_id = p.id
                          AND captured_date < cur.captured_date
                        ORDER BY captured_at DESC LIMIT 1)
                 WHERE cur.price_final IS NOT NULL AND prev.price_final IS NOT NULL
                   AND cur.price_final <> prev.price_final AND prev.price_final > 0
                   AND cur.in_stock = 1
                 ORDER BY ' . $order . '
                 LIMIT ' . $poolLimit . ' OFFSET ' . $poolOff;

        $rows = array_map([self::class, 'mapChange'], $this->db->query($sql)->fetchAll());

        if ($isDrop) {
            // Solo es descuento real si el precio actual está POR DEBAJO del habitual
            // (mediana). Volver de un precio más alto al de siempre no es oferta.
            $series = $this->priceSeries(array_map(static fn($r) => $r['id'], $rows), 45);
            $rows = array_values(array_filter($rows, static function (array $r) use ($series): bool {
                $prices = array_map(static fn($s) => (float) $s['p'], $series[$r['id']] ?? []);
                if (count($prices) < 3) { return true; }   // sin historia suficiente → no filtramos
                sort($prices);
                $m = intdiv(count($prices), 2);
                $median = count($prices) % 2 ? $prices[$m] : ($prices[$m - 1] + $prices[$m]) / 2;
                return $median > 0 && $r['price_now'] < $median * 0.995;
            }));
            $rows = array_slice($rows, $offset, $limit);
        }

        return $rows;
    }

    /** La baja más fuerte de CADA tienda (1 por tienda) — para el home. */
    public function topChangePerStore(): array
    {
        $seen = []; $out = [];
        foreach ($this->changesList('drop', 200, 0) as $r) {
            if ($r['direction'] !== 'down' || isset($seen[$r['store']])) { continue; }
            $seen[$r['store']] = true;
            $out[] = $r;
        }
        return $out;
    }

    /** Las 4 marcas Unicomer (mismo dueño, precio distinto entre sus tiendas). */
    private const UNICOMER = ['tropigas', 'gallo', 'radioshack', 'lacuracao'];

    /**
     * Mayores diferencias de precio entre tiendas para el MISMO producto, para la
     * página "precios que no tienen sentido". Solo matches CONFIABLES (exacto por
     * SKU/EAN o manual, nunca el difuso) y rango acotado, para no mostrar productos
     * distintos como iguales en una página pública. Marca las de "misma cadena".
     */
    public function biggestGaps(int $limit = 8, string $sort = 'diff'): array
    {
        $limit  = (int) max(1, min($limit, 30));
        $recent = $sort === 'recent';
        // En 'recent' traemos un pool más grande (por diferencia) y lo reordenamos
        // por última actualización de precio; así rota el contenido día a día.
        $pool = $recent ? min($limit * 6, 80) : $limit;

        $sql = "SELECT g.slug, g.canonical_title AS title, g.image_url,
                       MIN(p.last_price) AS min_price, MAX(p.last_price) AS max_price,
                       COUNT(DISTINCT p.store_id) AS store_count
                  FROM product_groups g
                  JOIN products p ON p.group_id = g.id AND p.is_active = 1
                 WHERE g.store_count >= 2 AND g.method IN ('uni','ean','manual')
                   AND p.last_in_stock = 1 AND p.last_price IS NOT NULL AND p.last_price < 1000000
                 GROUP BY g.id
                HAVING COUNT(DISTINCT p.store_id) >= 2 AND MIN(p.last_price) > 0
                   AND (MAX(p.last_price) - MIN(p.last_price)) / MIN(p.last_price) BETWEEN 0.15 AND 3.0
                 ORDER BY (MAX(p.last_price) - MIN(p.last_price)) / MIN(p.last_price) DESC
                 LIMIT " . $pool;

        $groups = $this->db->query($sql)->fetchAll();

        // Última fecha de CAMBIO de precio del grupo (máx entre sus miembros). Sólo
        // en 'recent'. El precio actual sale de p.last_price; el historial de cambio,
        // de price_history (que sí necesita la tabla).
        $lc = $this->db->prepare(
            'SELECT MAX((SELECT MAX(ph2.captured_date) FROM price_history ph2
                          WHERE ph2.product_id = p.id AND ph2.price_final <> p.last_price
                            AND ph2.price_final < 1000000))
               FROM products p
              WHERE p.group_id = ? AND p.is_active = 1'
        );

        // Oferta más barata y más cara (con tienda) por grupo.
        $off = $this->db->prepare(
            'SELECT s.slug AS store, s.name AS store_name, p.last_price AS price_final, p.last_currency AS currency
               FROM products p
               JOIN stores s ON s.id = p.store_id
              WHERE p.group_id = ? AND p.is_active = 1 AND p.last_in_stock = 1 AND p.last_price IS NOT NULL
              ORDER BY p.last_price ASC'
        );
        $gidStmt = $this->db->prepare('SELECT id FROM product_groups WHERE slug = ?');

        $out = [];
        foreach ($groups as $g) {
            $gidStmt->execute([$g['slug']]);
            $gid = (int) $gidStmt->fetchColumn();
            $off->execute([$gid]);
            $offers = $off->fetchAll();
            if (count($offers) < 2) { continue; }
            $cheap = $offers[0];
            $pricy = $offers[count($offers) - 1];
            $lo = (float) $cheap['price_final']; $hi = (float) $pricy['price_final'];
            $sameChain = in_array($cheap['store'], self::UNICOMER, true)
                      && in_array($pricy['store'], self::UNICOMER, true);
            $lastChange = null;
            if ($recent) { $lc->execute([$gid]); $lastChange = $lc->fetchColumn() ?: null; }
            $out[] = [
                'slug'        => $g['slug'],
                'title'       => Normalizer::cleanDisplayTitle($g['title']) ?: '(producto)',
                'image_url'   => $g['image_url'],
                'cheap_store' => $cheap['store_name'],
                'cheap_price' => $lo,
                'pricy_store' => $pricy['store_name'],
                'pricy_price' => $hi,
                'currency'    => $cheap['currency'] ?? 'NIO',
                'diff_pct'    => $lo > 0 ? (int) round(($hi - $lo) / $lo * 100) : null,
                'save'        => $hi - $lo,
                'stores'      => (int) $g['store_count'],
                'same_chain'  => $sameChain,
                'last_change' => $lastChange,
            ];
        }
        if ($recent) {
            // Los sin historial de cambio (null) al final; el resto por fecha desc.
            usort($out, static fn($a, $b) => strcmp((string) $b['last_change'], (string) $a['last_change']));
            $out = array_slice($out, 0, $limit);
        }
        return $out;
    }

    /**
     * Productos en (o casi en) su precio MÁS BAJO registrado, que además bajaron
     * de un pico — "mínimos históricos". Excluye centinela y exige historia mínima.
     */
    public function historicLows(int $limit = 12, string $sort = 'drop'): array
    {
        $recent = $sort === 'recent';
        // 'recent' = por fecha del último cambio de precio (novedad); si no, por
        // mayor caída desde el pico. La subconsulta de cambio sólo se agrega en 'recent'.
        $lastChangeCol = $recent
            ? ", (SELECT MAX(ph2.captured_date) FROM price_history ph2
                    WHERE ph2.product_id = p.id AND ph2.price_final <> p.last_price
                      AND ph2.price_final < 1000000) AS last_change"
            : '';
        $order = $recent ? 'last_change DESC' : '(agg.max_price - agg.min_price) / agg.min_price DESC';

        // Precio ACTUAL de p.last_*; el mín/máx HISTÓRICO sí sale de price_history (agg).
        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.name AS store_name, s.slug AS store,
                       p.last_price AS price_now, p.last_currency AS currency,
                       agg.min_price, agg.max_price' . $lastChangeCol . '
                  FROM products p
                  JOIN stores s ON s.id = p.store_id
                  JOIN (SELECT product_id, MIN(price_final) AS min_price, MAX(price_final) AS max_price, COUNT(*) AS n
                          FROM price_history
                         WHERE in_stock = 1 AND price_final IS NOT NULL AND price_final < 1000000
                           AND captured_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
                         GROUP BY product_id) agg ON agg.product_id = p.id
                 WHERE p.last_in_stock = 1 AND p.last_price IS NOT NULL AND p.last_price < 1000000
                   AND agg.n >= 5
                   AND p.last_price <= agg.min_price * 1.001
                   AND agg.max_price > agg.min_price * 1.03
                 ORDER BY ' . $order . '
                 LIMIT ' . (int) max(1, min($limit, 40));

        return array_map(static function (array $r): array {
            $now = (float) $r['price_now']; $max = (float) $r['max_price'];
            return [
                'id'         => (int) $r['id'],
                'title'      => $r['title'],
                'image_url'  => $r['image_url'],
                'url'        => $r['url'],
                'store_name' => $r['store_name'],
                'price_now'  => $now,
                'price_peak' => $max,
                'currency'   => $r['currency'] ?? 'NIO',
                'off_peak_pct' => $max > 0 ? (int) round(($max - $now) / $max * 100) : null,
                'last_change'  => $r['last_change'] ?? null,
            ];
        }, $this->db->query($sql)->fetchAll());
    }

    private static function mapChange(array $r): array
    {
        $now  = (float) $r['price_now'];
        $prev = (float) $r['price_prev'];
        return [
            'id'         => (int) $r['id'],
            'title'      => $r['title'],
            'brand'      => $r['brand'],
            'image_url'  => $r['image_url'],
            'url'        => $r['url'],
            'store'      => $r['store'],
            'store_name' => $r['store_name'],
            'price_now'  => $now,
            'price_prev' => $prev,
            'currency'   => $r['currency'] ?? 'NIO',
            'in_stock'   => (bool) $r['in_stock'],
            'date'       => $r['date_now'],
            'direction'  => $now < $prev ? 'down' : 'up',
            'delta_pct'  => $prev > 0 ? round(($now - $prev) / $prev * 100, 1) : null,
        ];
    }
}
