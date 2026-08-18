<?php
declare(strict_types=1);

/**
 * GET /api/admin/comparatives.php — ADMIN.
 * Lista los comparativos FUNCIONALES (con precio EN STOCK en ≥2 tiendas), ordenados
 * por la MAYOR diferencia de precio entre tiendas (igual criterio que el comparador
 * público), con su enlace al comparativo.
 *   ?q=texto  (busca en el título)   ?limit=50&offset=0
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$q      = trim((string) ($_GET['q'] ?? ''));
$limit  = min(200, max(1, (int) ($_GET['limit']  ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$where  = 'g.store_count >= 2';
$params = [];
if ($q !== '') {
    $where          .= ' AND g.canonical_title LIKE :q';
    $params[':q']    = '%' . $q . '%';
}

// Sólo tiendas EN STOCK (las agotadas traen precio-centinela) y con ≥2 tiendas reales.
$base = 'FROM product_groups g
          JOIN products p ON p.group_id = g.id AND p.is_active = 1
          JOIN price_history ph ON ph.id = (
                SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
         WHERE ' . $where . ' AND ph.in_stock = 1 AND ph.price_final IS NOT NULL
         GROUP BY g.id
         HAVING COUNT(DISTINCT p.store_id) >= 2';

$cnt = $db->prepare('SELECT COUNT(*) FROM (SELECT g.id ' . $base . ') t');
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$sql = 'SELECT g.slug, g.canonical_title AS title, g.brand, g.image_url,
               COUNT(DISTINCT p.store_id) AS store_count,
               COUNT(p.id) AS members,
               MIN(ph.price_final) AS min_price, MAX(ph.price_final) AS max_price,
               MAX(ph.currency) AS currency
          ' . $base . '
         ORDER BY (MAX(ph.price_final) - MIN(ph.price_final)) / NULLIF(MIN(ph.price_final), 0) DESC,
                  store_count DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset;
$st = $db->prepare($sql);
$st->execute($params);

$items = array_map(static function (array $r): array {
    $min = $r['min_price'] !== null ? (float) $r['min_price'] : null;
    $max = $r['max_price'] !== null ? (float) $r['max_price'] : null;
    return [
        'slug'     => $r['slug'],
        'title'    => $r['title'] ?: '(sin título)',
        'brand'    => $r['brand'],
        'image'    => $r['image_url'],
        'stores'   => (int) $r['store_count'],
        'members'  => (int) $r['members'],
        'min'      => $min,
        'max'      => $max,
        'diff'     => ($min !== null && $max !== null) ? $max - $min : null,
        'diff_pct' => ($min !== null && $min > 0 && $max !== null) ? (int) round((($max - $min) / $min) * 100) : null,
        'currency' => $r['currency'] ?? 'NIO',
        'url'      => 'producto.php?slug=' . rawurlencode((string) $r['slug']),
    ];
}, $st->fetchAll());

echo json_encode([
    'ok'     => true,
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'items'  => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
