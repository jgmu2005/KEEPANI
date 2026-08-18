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

$sort   = ($_GET['sort'] ?? 'diff') === 'new' ? 'new' : 'diff';

// La búsqueda va en el HAVING (no en el WHERE) para no descartar miembros que no
// coinciden y así conservar el conteo de tiendas y el rango de precios del grupo.
$having = 'COUNT(DISTINCT p.store_id) >= 2';
$params = [];
if ($q !== '') {
    $having        .= ' AND (g.canonical_title LIKE :qc OR SUM(p.title LIKE :qm) > 0)';
    $params[':qc']  = '%' . $q . '%';
    $params[':qm']  = '%' . $q . '%';
}

// Sólo tiendas EN STOCK (las agotadas traen precio-centinela) y con ≥2 tiendas reales.
$base = 'FROM product_groups g
          JOIN products p ON p.group_id = g.id AND p.is_active = 1
          JOIN price_history ph ON ph.id = (
                SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
         WHERE g.store_count >= 2 AND ph.in_stock = 1 AND ph.price_final IS NOT NULL
         GROUP BY g.id
         HAVING ' . $having;

$order = $sort === 'new'
    ? 'g.created_at DESC, g.id DESC'
    : '(MAX(ph.price_final) - MIN(ph.price_final)) / NULLIF(MIN(ph.price_final), 0) DESC, store_count DESC';

$cnt = $db->prepare('SELECT COUNT(*) FROM (SELECT g.id ' . $base . ') t');
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$sql = 'SELECT g.slug,
               COALESCE(NULLIF(g.canonical_title, \'\'), MAX(p.title)) AS title,
               COALESCE(NULLIF(g.brand, \'\'), MAX(p.brand))          AS brand,
               COALESCE(NULLIF(g.image_url, \'\'), MAX(p.image_url))   AS image_url,
               COUNT(DISTINCT p.store_id) AS store_count,
               COUNT(p.id) AS members,
               MIN(ph.price_final) AS min_price, MAX(ph.price_final) AS max_price,
               MAX(ph.currency) AS currency, g.created_at
          ' . $base . '
         ORDER BY ' . $order . '
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
        'created'  => $r['created_at'] ?? null,
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
