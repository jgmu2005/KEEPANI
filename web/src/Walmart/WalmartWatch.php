<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Walmart;

use PDO;

/**
 * "Cazaofertas Walmart" — detección de liquidaciones sobre el catálogo completo.
 *
 * Guarda UNA fila por producto (wm_products, precio actual + precio de referencia
 * = el "normal") y sólo registra un evento en wm_drops cuando el precio BAJA y
 * queda ≥ THRESHOLD por debajo de su referencia. Así el almacenamiento no crece
 * con el catálogo entero: N filas + un log corto de liquidaciones.
 */
final class WalmartWatch
{
    /** Baja mínima (vs precio de referencia) para contar como liquidación. */
    public const THRESHOLD = 0.30; // 30%

    public function __construct(private PDO $db) {}

    /**
     * Procesa un lote del crawler (items con el shape de VtexMapper::toArray()).
     * @return array{seen:int, inserted:int, drops:int}
     */
    public function ingestBatch(array $items): array
    {
        $sel = $this->db->prepare('SELECT id, price_current, price_ref FROM wm_products WHERE sku = ? LIMIT 1');
        $ins = $this->db->prepare(
            'INSERT INTO wm_products (sku, title, brand, url, image_url, price_current, price_ref, in_stock, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $upd = $this->db->prepare(
            'UPDATE wm_products
                SET title = COALESCE(?, title), brand = COALESCE(?, brand),
                    url = COALESCE(?, url), image_url = COALESCE(?, image_url),
                    price_current = ?, price_ref = ?, in_stock = ?, currency = ?,
                    last_seen = NOW(), last_drop_at = ?
              WHERE id = ?'
        );
        $drop = $this->db->prepare(
            'INSERT INTO wm_drops (product_id, old_price, new_price, ref_price, pct, in_stock)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $seen = 0; $inserted = 0; $drops = 0;

        foreach ($items as $it) {
            $sku   = isset($it['sku']) ? (string) $it['sku'] : '';
            $price = isset($it['price_final']) && $it['price_final'] !== null ? (float) $it['price_final'] : null;
            if ($sku === '' || $price === null || $price <= 0) { continue; }
            $seen++;

            $list    = isset($it['list_price']) && $it['list_price'] !== null ? (float) $it['list_price'] : 0.0;
            $inStock = !empty($it['in_stock']) ? 1 : 0;
            $cur     = $it['currency'] ?? 'NIO';
            $title   = $it['title']     ?? null;
            $brand   = $it['brand']     ?? null;
            $url     = $it['url']       ?? null;
            $img     = $it['image_url'] ?? null;

            $sel->execute([$sku]);
            $row = $sel->fetch();

            // Referencia = el precio "normal": máximo entre el de lista, lo ya visto y el actual.
            $refCand = max($list, $price);

            if (!$row) {
                $ins->execute([$sku, $title, $brand, $url, $img, $price, $refCand, $inStock, $cur]);
                $inserted++;
                continue;
            }

            $prev    = $row['price_current'] !== null ? (float) $row['price_current'] : null;
            $newRef  = max((float) ($row['price_ref'] ?? 0), $refCand);
            $pct     = $newRef > 0 ? ($newRef - $price) / $newRef : 0.0;
            $dropped = $prev !== null && $price < $prev - 0.005;   // bajó respecto a la captura anterior
            $isDrop  = $dropped && $inStock === 1 && $pct >= self::THRESHOLD;

            if ($isDrop) {
                $drop->execute([(int) $row['id'], $prev, $price, $newRef, round($pct * 100, 2), $inStock]);
                $drops++;
            }
            $upd->execute([$title, $brand, $url, $img, $price, $newRef, $inStock, $cur, $isDrop ? date('Y-m-d H:i:s') : null, (int) $row['id']]);
        }

        return ['seen' => $seen, 'inserted' => $inserted, 'drops' => $drops];
    }

    /**
     * Feed de liquidaciones recientes (últimos $days días, en stock).
     * @return array{total:int, items:array}
     */
    public function feed(int $limit = 40, int $offset = 0, string $sort = 'recent', int $days = 21): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $days   = max(1, min($days, 90));
        $order  = $sort === 'pct' ? 'd.pct DESC, d.detected_at DESC' : 'd.detected_at DESC, d.pct DESC';

        // Un evento (el más reciente) por producto dentro de la ventana.
        $base = 'FROM wm_drops d
                 JOIN wm_products p ON p.id = d.product_id
                 JOIN (SELECT product_id, MAX(id) AS mid
                         FROM wm_drops
                        WHERE detected_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                        GROUP BY product_id) last ON last.mid = d.id
                WHERE p.in_stock = 1';

        $total = (int) $this->db->query('SELECT COUNT(*) ' . $base)->fetchColumn();

        $sql = 'SELECT p.title, p.brand, p.url, p.image_url, p.currency,
                       d.old_price, d.new_price, d.ref_price, d.pct, d.detected_at
                ' . $base . '
                ORDER BY ' . $order . '
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $items = array_map(static function (array $r): array {
            return [
                'title'       => $r['title'],
                'brand'       => $r['brand'],
                'url'         => $r['url'],
                'image_url'   => $r['image_url'],
                'currency'    => $r['currency'] ?? 'NIO',
                'old_price'   => (float) $r['old_price'],
                'new_price'   => (float) $r['new_price'],
                'ref_price'   => (float) $r['ref_price'],
                'pct'         => (float) $r['pct'],
                'detected_at' => $r['detected_at'],
            ];
        }, $this->db->query($sql)->fetchAll());

        return ['total' => $total, 'items' => $items];
    }
}
