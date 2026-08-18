<?php
declare(strict_types=1);

/**
 * GET /api/admin/comparatives.php — ADMIN.
 * Lista los comparativos FUNCIONALES (grupos con ≥2 tiendas) con su enlace público.
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

$where  = 'store_count >= 2';
$params = [];
if ($q !== '') {
    $where     .= ' AND canonical_title LIKE ?';
    $params[]   = '%' . $q . '%';
}

$cnt = $db->prepare("SELECT COUNT(*) FROM product_groups WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$st = $db->prepare(
    "SELECT slug, canonical_title, brand, image_url, store_count, member_count
       FROM product_groups
      WHERE $where
      ORDER BY store_count DESC, member_count DESC, canonical_title ASC
      LIMIT $limit OFFSET $offset"
);
$st->execute($params);

$items = array_map(static fn($r) => [
    'slug'    => $r['slug'],
    'title'   => $r['canonical_title'] ?: '(sin título)',
    'brand'   => $r['brand'],
    'image'   => $r['image_url'],
    'stores'  => (int) $r['store_count'],
    'members' => (int) $r['member_count'],
    'url'     => 'producto.php?slug=' . rawurlencode((string) $r['slug']),
], $st->fetchAll());

echo json_encode([
    'ok'     => true,
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'items'  => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
