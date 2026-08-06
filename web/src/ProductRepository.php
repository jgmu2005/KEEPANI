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
            // Copasa OG: .../Product/Detail/{sku}
            'og_meta' => (preg_match('~/Product/Detail/([^/?#]+)~i', $url, $m) ? rawurldecode($m[1]) : null),
            default   => null,
        };

        return [$store['slug'], $sku];
    }

    /** Ficha del producto + datos de su tienda. */
    public function product(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.id, p.external_sku AS sku, p.title, p.brand, p.image_url, p.url,
                    s.slug AS store, s.name AS store_name, s.currency
               FROM products p
               JOIN stores s ON s.id = p.store_id
              WHERE p.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
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
                 LEFT JOIN price_history ph ON ph.id = (
                     SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1
                 )
                 WHERE ' . $whereSql;

        $countStmt = $this->db->prepare('SELECT COUNT(*) ' . $base);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT p.id, p.title, p.brand, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name,
                       ph.price_final, ph.currency, ph.in_stock, ph.captured_date AS last_date
                ' . $base . '
                ORDER BY ' . $orderSql . '
                LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $items = array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'title'       => $r['title'],
                'brand'       => $r['brand'],
                'image_url'   => $r['image_url'],
                'url'         => $r['url'],
                'store'       => $r['store'],
                'store_name'  => $r['store_name'],
                'price_final' => $r['price_final'] !== null ? (float) $r['price_final'] : null,
                'currency'    => $r['currency'] ?? 'NIO',
                'in_stock'    => (bool) $r['in_stock'],
                'last_date'   => $r['last_date'],
            ];
        }, $stmt->fetchAll());

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
                 ORDER BY cur.captured_at DESC
                 LIMIT ' . $limit;

        return array_map(static function (array $r): array {
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
        }, $this->db->query($sql)->fetchAll());
    }
}
