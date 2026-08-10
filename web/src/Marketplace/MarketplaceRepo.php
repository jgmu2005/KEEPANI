<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Marketplace;

use PDO;

/**
 * Acceso a las tablas mk_* (marketplace Treinta). Totalmente separado del
 * tracker principal.
 */
final class MarketplaceRepo
{
    public function __construct(private PDO $db) {}

    /** Tiendas activas para el crawler. */
    public function activeStores(): array
    {
        return $this->db->query('SELECT * FROM mk_stores WHERE is_active = 1 ORDER BY id')->fetchAll();
    }

    /** Todas las tiendas (admin), con conteo de productos. */
    public function allStores(): array
    {
        return $this->db->query(
            'SELECT s.*, (SELECT COUNT(*) FROM mk_products p WHERE p.store_id = s.id AND p.is_active = 1) AS product_count
               FROM mk_stores s ORDER BY s.name'
        )->fetchAll();
    }

    /** Tiendas con productos (para el filtro de la página pública). */
    public function storeFilter(): array
    {
        return $this->db->query(
            'SELECT s.slug, s.name, COUNT(p.id) AS n
               FROM mk_stores s JOIN mk_products p ON p.store_id = s.id AND p.is_active = 1
              WHERE s.is_active = 1
              GROUP BY s.id HAVING n > 0 ORDER BY s.name'
        )->fetchAll();
    }

    /** Agrega una tienda por URL de Treinta. Devuelve [ok, error?]. */
    public function addStore(string $url): array
    {
        $url  = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_ends_with($host, 'treinta.co')) {
            return ['ok' => false, 'error' => 'La URL debe ser de treinta.co (catalogo/ o tienda/).'];
        }
        $slug = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = explode('/', $slug)[0] ?? '';
        if ($slug === '') {
            return ['ok' => false, 'error' => 'No se pudo extraer el slug de la tienda.'];
        }
        $clean = 'https://' . $host . '/' . $slug;
        $st = $this->db->prepare(
            'INSERT INTO mk_stores (slug, name, url) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE url = VALUES(url), is_active = 1'
        );
        $st->execute([$slug, $slug, $clean]);
        return ['ok' => true, 'slug' => $slug];
    }

    public function findStore(string $slug): ?array
    {
        $st = $this->db->prepare('SELECT * FROM mk_stores WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function setStoreActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE mk_stores SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    /** Upsert de un producto + fila de precio del día. */
    public function ingestProduct(int $storeId, array $it): void
    {
        $up = $this->db->prepare(
            'INSERT INTO mk_products (store_id, ext_id, name, image_url, price, currency, in_stock, is_active, last_seen)
             VALUES (:s, :e, :n, :img, :p, :cur, :st, 1, NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), image_url = VALUES(image_url), price = VALUES(price),
                currency = VALUES(currency), in_stock = VALUES(in_stock), is_active = 1, last_seen = NOW()'
        );
        $up->execute([
            ':s' => $storeId, ':e' => $it['ext_id'], ':n' => mb_substr($it['name'], 0, 250),
            ':img' => $it['image_url'] ?: null, ':p' => $it['price'], ':cur' => $it['currency'] ?? 'NIO',
            ':st' => (int) ($it['in_stock'] ?? 1),
        ]);
        // id del producto (lastInsertId no sirve si fue UPDATE por ON DUPLICATE KEY).
        $sel = $this->db->prepare('SELECT id FROM mk_products WHERE store_id = ? AND ext_id = ?');
        $sel->execute([$storeId, $it['ext_id']]);
        $pid = (int) $sel->fetchColumn();
        if ($pid <= 0) { return; }

        $this->db->prepare(
            'INSERT INTO mk_price_history (product_id, captured_date, price, in_stock)
             VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price), in_stock = VALUES(in_stock)'
        )->execute([$pid, $it['price'], (int) ($it['in_stock'] ?? 1)]);
    }

    /** Marca inactivos los productos de la tienda que ya no aparecieron hoy. */
    public function deactivateMissing(int $storeId, array $seenExtIds): int
    {
        if (!$seenExtIds) {
            return (int) $this->db->prepare('UPDATE mk_products SET is_active = 0 WHERE store_id = ?')
                ->execute([$storeId]);
        }
        $ph = implode(',', array_fill(0, count($seenExtIds), '?'));
        $sql = "UPDATE mk_products SET is_active = 0 WHERE store_id = ? AND ext_id NOT IN ($ph)";
        $st  = $this->db->prepare($sql);
        $st->execute(array_merge([$storeId], $seenExtIds));
        return $st->rowCount();
    }

    public function touchStore(int $id, ?string $name): void
    {
        if ($name !== null && $name !== '') {
            $this->db->prepare('UPDATE mk_stores SET name = ?, last_crawl = NOW() WHERE id = ?')->execute([$name, $id]);
        } else {
            $this->db->prepare('UPDATE mk_stores SET last_crawl = NOW() WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * Productos para la página pública, con precio actual + mín/máx histórico.
     * @return array{total:int, items:array}
     */
    public function listProducts(?string $storeSlug, int $limit, int $offset, string $sort = 'recent', bool $hideOutOfStock = true): array
    {
        $where  = 'p.is_active = 1 AND s.is_active = 1 AND p.price IS NOT NULL';
        $params = [];
        if ($hideOutOfStock) {
            $where .= ' AND p.in_stock = 1';
        }
        if ($storeSlug) {
            $where .= ' AND s.slug = :slug';
            $params[':slug'] = $storeSlug;
        }

        $order = [
            'recent'     => 'p.last_seen DESC, p.id DESC',
            'price_asc'  => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            'name'       => 'p.name ASC',
        ][$sort] ?? 'p.last_seen DESC, p.id DESC';

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM mk_products p JOIN mk_stores s ON s.id = p.store_id WHERE $where");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $sql = "SELECT p.id, p.name, p.image_url, p.price, p.currency, p.in_stock,
                       s.slug AS store, s.name AS store_name, s.url AS store_url, s.whatsapp,
                       (SELECT MIN(price) FROM mk_price_history WHERE product_id = p.id AND price IS NOT NULL) AS min_price,
                       (SELECT MAX(price) FROM mk_price_history WHERE product_id = p.id AND price IS NOT NULL) AS max_price
                  FROM mk_products p JOIN mk_stores s ON s.id = p.store_id
                 WHERE $where
                 ORDER BY $order
                 LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        $st = $this->db->prepare($sql);
        $st->execute($params);

        $items = array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'name'       => $r['name'],
                'image_url'  => $r['image_url'],
                'price'      => $r['price'] !== null ? (float) $r['price'] : null,
                'min_price'  => $r['min_price'] !== null ? (float) $r['min_price'] : null,
                'max_price'  => $r['max_price'] !== null ? (float) $r['max_price'] : null,
                'currency'   => $r['currency'] ?? 'NIO',
                'in_stock'   => (bool) $r['in_stock'],
                'store'      => $r['store'],
                'store_name' => $r['store_name'],
                'store_url'  => $r['store_url'],
                'whatsapp'   => $r['whatsapp'],
            ];
        }, $st->fetchAll());

        return ['total' => $total, 'items' => $items];
    }
}
