<?php
declare(strict_types=1);

/**
 * /api/hashes.php — soporte para el hasheo remoto (GitHub Actions).
 * Autenticación: header  X-Api-Key: <ingest_api_key>
 *
 *   GET  ?limit=N&after=ID  → productos con imagen y sin img_dhash, con id > after
 *                             (cursor para avanzar aunque algunos fallen):
 *                             { items:[{id,image_url}], remaining }
 *   POST { items:[{id, dhash}] }  → guarda los dHash calculados: { updated }
 *
 * El trabajo pesado (bajar+hashear) lo hace el runner de GitHub; acá solo
 * leemos/escribimos la BD.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ImageHash;

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
    $limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 1000)) : 300;
    $after = isset($_GET['after']) ? max(0, (int) $_GET['after']) : 0;
    $rows = $db->query(
        'SELECT id, image_url FROM products
          WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> "" AND img_dhash IS NULL
            AND id > ' . $after . '
          ORDER BY id LIMIT ' . $limit
    )->fetchAll();
    $remaining = (int) $db->query(
        'SELECT COUNT(*) FROM products
          WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> "" AND img_dhash IS NULL'
    )->fetchColumn();
    out(200, [
        'ok'        => true,
        'items'     => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'image_url' => $r['image_url']], $rows),
        'remaining' => $remaining,
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        out(400, ['ok' => false, 'error' => 'JSON inválido: falta items[]']);
    }
    $upd = $db->prepare('UPDATE products SET img_dhash = ? WHERE id = ?');
    $updated = 0; $skipped = 0;
    foreach ($body['items'] as $it) {
        $id   = (int) ($it['id'] ?? 0);
        $hash = strtolower((string) ($it['dhash'] ?? ''));
        if ($id <= 0 || !ImageHash::isValidHex($hash)) { $skipped++; continue; }
        $upd->execute([$hash, $id]);
        $updated += $upd->rowCount() > 0 ? 1 : 0;
    }
    out(200, ['ok' => true, 'updated' => $updated, 'skipped' => $skipped]);
}

out(405, ['ok' => false, 'error' => 'Método no permitido']);
