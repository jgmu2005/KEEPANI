<?php
declare(strict_types=1);

/** GET /api/admin/matches.php — ADMIN. Candidatos pendientes de revisión (slice 2b). */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$sql = "SELECT mr.id, mr.score, mr.img_distance, mr.jaccard, mr.method,
               a.id AS a_id, a.title AS a_title, a.image_url AS a_img, a.url AS a_url,
               sa.name AS a_store, pha.price_final AS a_price,
               b.id AS b_id, b.title AS b_title, b.image_url AS b_img, b.url AS b_url,
               sb.name AS b_store, phb.price_final AS b_price
          FROM match_review mr
          JOIN products a ON a.id = mr.product_a_id  JOIN stores sa ON sa.id = a.store_id
          JOIN products b ON b.id = mr.product_b_id  JOIN stores sb ON sb.id = b.store_id
          LEFT JOIN price_history pha ON pha.id = (SELECT id FROM price_history WHERE product_id = a.id ORDER BY captured_at DESC LIMIT 1)
          LEFT JOIN price_history phb ON phb.id = (SELECT id FROM price_history WHERE product_id = b.id ORDER BY captured_at DESC LIMIT 1)
         WHERE mr.status = 'pending'
         ORDER BY mr.score DESC, mr.id ASC
         LIMIT 60";

$items = array_map(static function (array $r): array {
    return [
        'id'      => (int) $r['id'],
        'score'   => (int) $r['score'],
        'img'     => $r['img_distance'] !== null ? (int) $r['img_distance'] : null,
        'jaccard' => (float) $r['jaccard'],
        'method'  => $r['method'],
        'a' => ['title' => $r['a_title'], 'img' => $r['a_img'], 'url' => $r['a_url'], 'store' => $r['a_store'], 'price' => $r['a_price'] !== null ? (float) $r['a_price'] : null],
        'b' => ['title' => $r['b_title'], 'img' => $r['b_img'], 'url' => $r['b_url'], 'store' => $r['b_store'], 'price' => $r['b_price'] !== null ? (float) $r['b_price'] : null],
    ];
}, $db->query($sql)->fetchAll());

echo json_encode(
    ['ok' => true, 'items' => $items,
     'pending' => (int) $db->query("SELECT COUNT(*) FROM match_review WHERE status='pending'")->fetchColumn()],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
