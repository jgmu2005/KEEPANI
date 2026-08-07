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
                return $this->bySlugSku($slug, $extractedSku);
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
            // VTEX: .../slug-del-producto-{ref-o-productId}/p
            'vtex'    => (preg_match('~-(\d+)(?:/p)?/?(?:[?#].*)?$~', $url, $m) ? $m[1] : null),
            // OG: Copasa (.../Product/Detail/{sku}), Gallo (.../slug-{id}) o Unicomer (.../slug-{id}/p)
            'og_meta' => (
                preg_match('~/Product/Detail/([^/?#]+)~i', $url, $m) ? rawurldecode($m[1])
                    : (preg_match('~-(\d+)(?:/p)?/?(?:[?#].*)?$~', $url, $m2) ? $m2[1] : null)
            ),
            // PriceSmart: .../es-ni/producto/{slug}/{pid} → el pid es el último número.
            'bloomreach' => (preg_match('~/(\d+)/?(?:[?#].*)?$~', $url, $m) ? $m[1] : null),
            default   => null,
        };

        return [$store['slug'], $sku];
    }

    /** Ficha del producto + datos de su tienda. */
    public function product(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.id, p.external_sku AS sku, p.title, p.brand, p.image_url, p.url,
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

        return [
            'current'   => $last['price_final'] ?? null,
            'min'       => $prices ? min($prices) : null,
            'max'       => $prices ? max($prices) : null,
            'in_stock'  => $last['in_stock'] ?? false,
            'currency'  => $last['currency'] ?? 'NIO',
            'points'    => count($history),
            'last_date' => $last['date'] ?? null,
        ];
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
               JOIN price_history ph ON ph.id = (
                    SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
              WHERE p.is_active = 1 AND p.cat_key IS NOT NULL AND ph.in_stock = 1
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
            $where[] = 'p.title LIKE :q';
            $params[':q'] = '%' . $f['q'] . '%';
        }
        if (!empty($f['store'])) {
            $where[] = 's.slug = :store';
            $params[':store'] = $f['store'];
        }
        if (isset($f['min']) && $f['min'] !== '' && $f['min'] !== null) {
            $where[] = 'ph.price_final >= :min';
            $params[':min'] = (float) $f['min'];
        }
        if (isset($f['max']) && $f['max'] !== '' && $f['max'] !== null) {
            $where[] = 'ph.price_final <= :max';
            $params[':max'] = (float) $f['max'];
        }
        if (!empty($f['in_stock'])) {
            $where[] = 'ph.in_stock = 1';
        }
        if (!empty($f['category'])) {
            $where[] = 'p.category_external_id = :cat';
            $params[':cat'] = (int) $f['category'];
        }
        if (!empty($f['cat_key'])) {
            $where[] = 'p.cat_key = :catkey';
            $params[':catkey'] = (string) $f['cat_key'];
        }
        $whereSql = implode(' AND ', $where);

        // Orden: lista blanca (nunca interpolar entrada del usuario).
        $sortMap = [
            'name'       => 'p.title ASC',
            'price_asc'  => 'ph.price_final ASC',
            'price_desc' => 'ph.price_final DESC',
            'discount'   => 'ph.discount_pct DESC',
        ];
        $orderSql = $sortMap[$f['sort'] ?? 'name'] ?? $sortMap['name'];

        $limit  = max(1, min((int) ($f['limit'] ?? 50), 10000));
        $offset = max(0, (int) ($f['offset'] ?? 0));

        // FROM + WHERE compartido entre el conteo y la página.
        $base = 'FROM products p
                 JOIN stores s ON s.id = p.store_id
                 LEFT JOIN product_groups pg ON pg.id = p.group_id
                 LEFT JOIN price_history ph ON ph.id = (
                     SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1
                 )
                 WHERE ' . $whereSql;

        $countStmt = $this->db->prepare('SELECT COUNT(*) ' . $base);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name, s.tax_included,
                       ph.price_final, ph.list_price, ph.discount_pct,
                       ph.currency, ph.in_stock, ph.captured_date AS last_date,
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
                'discount_pct' => $r['discount_pct'] !== null ? (float) $r['discount_pct'] : null,
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
            'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                    s.slug AS store, s.name AS store_name, s.tax_included,
                    ph.price_final, ph.list_price, ph.currency, ph.in_stock, ph.captured_date AS last_date
               FROM products p
               JOIN stores s ON s.id = p.store_id
               LEFT JOIN price_history ph ON ph.id = (
                    SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
              WHERE p.group_id = ? AND p.is_active = 1
              ORDER BY (ph.price_final IS NULL), ph.price_final ASC'
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

        // Sólo cuentan las ofertas EN STOCK: las agotadas traen precio-centinela
        // (ej. Siman marca C$10,000,000) que inflaba el máximo y el % de ahorro.
        // Se recalcula el # de tiendas con stock y se exige que sigan siendo ≥2.
        $base = 'FROM product_groups g
                  JOIN products p ON p.group_id = g.id AND p.is_active = 1
                  JOIN price_history ph ON ph.id = (
                        SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
                 WHERE ' . $where . ' AND ph.in_stock = 1 AND ph.price_final IS NOT NULL
                 GROUP BY g.id
                 HAVING COUNT(DISTINCT p.store_id) >= 2';

        $cnt = $this->db->prepare('SELECT COUNT(*) FROM (SELECT g.id ' . $base . ') t');
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        // Orden: por diferencia de precio % entre tiendas (default) o por # de tiendas.
        $order = $sort === 'stores'
            ? 'store_count DESC, g.updated_at DESC'
            : '(MAX(ph.price_final) - MIN(ph.price_final)) / NULLIF(MIN(ph.price_final), 0) DESC, store_count DESC';

        $sql = 'SELECT g.slug, g.canonical_title AS title, g.brand, g.image_url,
                       COUNT(DISTINCT p.store_id) AS store_count,
                       MIN(ph.price_final) AS min_price, MAX(ph.price_final) AS max_price,
                       MAX(ph.currency) AS currency
                  ' . $base . '
                 ORDER BY ' . $order . '
                 LIMIT ' . $limit . ' OFFSET ' . $offset;
        $st = $this->db->prepare($sql);
        $st->execute($params);

        $items = array_map(static function (array $r): array {
            return [
                'slug'        => $r['slug'],
                'title'       => $r['title'],
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
        $order  = $sort === 'rise' ? 'delta DESC' : 'delta ASC';

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
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        return array_map([self::class, 'mapChange'], $this->db->query($sql)->fetchAll());
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
