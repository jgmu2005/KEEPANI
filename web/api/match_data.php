<?php
declare(strict_types=1);

/**
 * /api/match_data.php — soporte para el matcher remoto (GitHub Actions, 2b).
 * Autenticación: header X-Api-Key: <ingest_api_key>
 *
 *   GET  ?after=<brand>&limit=<n>  → próximos N marcas multi-tienda (nombre >
 *        after) con sus productos: { blocks:[{brand, products:[...]}], next }
 *   POST { items:[{a,b,score,img,jac,method}] } → encola candidatos en match_review
 *
 * El scoring O(n²) lo hace el runner de GitHub (no FatCow). Acá solo leemos
 * bloques y escribimos candidatos.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$db = Db::conn();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 100)) : 25;
    $after = (string) ($_GET['after'] ?? '');

    // Próximas marcas multi-tienda después del cursor (alfabético).
    $bs = $db->prepare(
        "SELECT brand_norm FROM products
          WHERE is_active = 1 AND brand_norm IS NOT NULL AND brand_norm <> ''
            AND model_norm IS NOT NULL AND brand_norm > ?
          GROUP BY brand_norm HAVING COUNT(DISTINCT store_id) >= 2
          ORDER BY brand_norm LIMIT $limit"
    );
    $bs->execute([$after]);
    $brands = $bs->fetchAll(\PDO::FETCH_COLUMN);

    $blocks = [];
    $next = $after;
    if ($brands) {
        $place = implode(',', array_fill(0, count($brands), '?'));
        $ps = $db->prepare(
            "SELECT p.id, p.brand_norm, p.store_id, p.model_norm, p.img_dhash, p.group_id,
                    p.last_price AS price
               FROM products p
              WHERE p.is_active = 1 AND p.model_norm IS NOT NULL AND p.brand_norm IN ($place)"
        );
        $ps->execute($brands);
        $byBrand = [];
        foreach ($ps->fetchAll() as $r) {
            $byBrand[$r['brand_norm']][] = [
                'id'        => (int) $r['id'],
                'store_id'  => (int) $r['store_id'],
                'model_norm'=> $r['model_norm'],
                'img_dhash' => $r['img_dhash'],
                'group_id'  => $r['group_id'] !== null ? (int) $r['group_id'] : null,
                'price'     => $r['price'] !== null ? (float) $r['price'] : null,
            ];
        }
        foreach ($brands as $b) {
            $blocks[] = ['brand' => $b, 'products' => $byBrand[$b] ?? []];
        }
        $next = (string) end($brands);
    }

    out(200, ['ok' => true, 'blocks' => $blocks, 'next' => $next, 'done' => count($brands) < $limit]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        out(400, ['ok' => false, 'error' => 'JSON inválido: falta items[]']);
    }
    $ins = $db->prepare(
        'INSERT IGNORE INTO match_review (product_a_id, product_b_id, score, img_distance, jaccard, method)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $inserted = 0;
    foreach ($body['items'] as $it) {
        $a = (int) ($it['a'] ?? 0); $b = (int) ($it['b'] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) { continue; }
        if ($a > $b) { [$a, $b] = [$b, $a]; } // par canónico
        $img = isset($it['img']) && $it['img'] !== null ? (int) $it['img'] : null;
        $method2 = ($it['method'] ?? 'title') === 'image' ? 'image' : 'title';
        $ins->execute([$a, $b, (int) ($it['score'] ?? 0), $img, (float) ($it['jac'] ?? 0), $method2]);
        $inserted += $ins->rowCount() > 0 ? 1 : 0;
    }
    out(200, ['ok' => true, 'inserted' => $inserted]);
}

out(405, ['ok' => false, 'error' => 'Método no permitido']);
