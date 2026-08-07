<?php
declare(strict_types=1);

/**
 * GET /api/admin/matches.php — ADMIN. Candidatos pendientes de revisión (slice 2b).
 * Parámetros:
 *   ?limit=20&offset=0
 *   ?sort=score|recent
 *   ?category=<cat_key>            (celulares, tv, …; filtra por la categoría del producto)
 *   ?hide_oos=1                    (oculta pares con algún producto agotado)
 * Los pares con algún producto agotado se ordenan SIEMPRE al final (salvo que se oculten).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\CategoryClassifier;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$limit   = max(1, min((int) ($_GET['limit'] ?? 20), 60));
$offset  = max(0, (int) ($_GET['offset'] ?? 0));
$sort    = ($_GET['sort'] ?? 'score') === 'recent' ? 'recent' : 'score';
$hideOos = !empty($_GET['hide_oos']);
$catReq  = trim((string) ($_GET['category'] ?? ''));
$hasCat  = (bool) $db->query("SHOW COLUMNS FROM products LIKE 'cat_key'")->fetch();
$category = ($hasCat && $catReq !== '' && isset(CategoryClassifier::LABELS[$catReq])) ? $catReq : '';

$where  = ["mr.status = 'pending'"];
$params = [];
if ($category !== '') {
    // Placeholders distintos: el PDO corre con EMULATE_PREPARES=false y no
    // permite reusar un named param en la misma sentencia.
    $where[] = '(a.cat_key = :cata OR b.cat_key = :catb)';
    $params[':cata'] = $category;
    $params[':catb'] = $category;
}
// "En stock" = último precio con in_stock=1 en AMBOS productos.
$bothIn = '(COALESCE(pha.in_stock,0) = 1 AND COALESCE(phb.in_stock,0) = 1)';
if ($hideOos) {
    $where[] = $bothIn;
}
$whereSql = implode(' AND ', $where);

// Los agotados al final; luego el orden elegido.
$order = $sort === 'recent'
    ? "$bothIn DESC, mr.created_at DESC, mr.id DESC"
    : "$bothIn DESC, mr.score DESC, mr.id ASC";

$sql = "SELECT mr.id, mr.score, mr.img_distance, mr.jaccard, mr.method, mr.created_at,
               a.title AS a_title, a.image_url AS a_img, a.url AS a_url,
               sa.name AS a_store, pha.price_final AS a_price, COALESCE(pha.in_stock,0) AS a_stock,
               b.title AS b_title, b.image_url AS b_img, b.url AS b_url,
               sb.name AS b_store, phb.price_final AS b_price, COALESCE(phb.in_stock,0) AS b_stock
          FROM match_review mr
          JOIN products a ON a.id = mr.product_a_id  JOIN stores sa ON sa.id = a.store_id
          JOIN products b ON b.id = mr.product_b_id  JOIN stores sb ON sb.id = b.store_id
          LEFT JOIN price_history pha ON pha.id = (SELECT id FROM price_history WHERE product_id = a.id ORDER BY captured_at DESC LIMIT 1)
          LEFT JOIN price_history phb ON phb.id = (SELECT id FROM price_history WHERE product_id = b.id ORDER BY captured_at DESC LIMIT 1)
         WHERE $whereSql
         ORDER BY $order
         LIMIT $limit OFFSET $offset";
$st = $db->prepare($sql);
$st->execute($params);

$items = array_map(static function (array $r): array {
    return [
        'id'      => (int) $r['id'],
        'score'   => (int) $r['score'],
        'img'     => $r['img_distance'] !== null ? (int) $r['img_distance'] : null,
        'jaccard' => (float) $r['jaccard'],
        'method'  => $r['method'],
        'a' => ['title' => $r['a_title'], 'img' => $r['a_img'], 'url' => $r['a_url'], 'store' => $r['a_store'], 'price' => $r['a_price'] !== null ? (float) $r['a_price'] : null, 'in_stock' => (bool) $r['a_stock']],
        'b' => ['title' => $r['b_title'], 'img' => $r['b_img'], 'url' => $r['b_url'], 'store' => $r['b_store'], 'price' => $r['b_price'] !== null ? (float) $r['b_price'] : null, 'in_stock' => (bool) $r['b_stock']],
    ];
}, $st->fetchAll());

// Total con los MISMOS filtros (para saber si hay "Ver más").
$cntSql = "SELECT COUNT(*)
             FROM match_review mr
             JOIN products a ON a.id = mr.product_a_id
             JOIN products b ON b.id = mr.product_b_id
             LEFT JOIN price_history pha ON pha.id = (SELECT id FROM price_history WHERE product_id = a.id ORDER BY captured_at DESC LIMIT 1)
             LEFT JOIN price_history phb ON phb.id = (SELECT id FROM price_history WHERE product_id = b.id ORDER BY captured_at DESC LIMIT 1)
            WHERE $whereSql";
$cnt = $db->prepare($cntSql);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

// Categorías presentes en los pares pendientes (para el filtro).
$categories = [];
if ($hasCat) {
    $catRows = $db->query(
        "SELECT p.cat_key AS k, COUNT(*) AS n FROM (
            SELECT product_a_id AS pid FROM match_review WHERE status='pending'
            UNION ALL
            SELECT product_b_id AS pid FROM match_review WHERE status='pending'
         ) x JOIN products p ON p.id = x.pid
         WHERE p.cat_key IS NOT NULL GROUP BY p.cat_key"
    )->fetchAll(\PDO::FETCH_KEY_PAIR);
    foreach (CategoryClassifier::LABELS as $key => $label) {
        if (!empty($catRows[$key])) {
            $categories[] = ['key' => $key, 'label' => $label, 'count' => (int) $catRows[$key]];
        }
    }
}

echo json_encode(
    ['ok' => true, 'items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit,
     'categories' => $categories,
     'pending' => (int) $db->query("SELECT COUNT(*) FROM match_review WHERE status='pending'")->fetchColumn()],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
