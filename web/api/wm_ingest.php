<?php
declare(strict_types=1);

/**
 * /api/wm_ingest.php — ingesta REMOTA del cazaofertas de Walmart (GitHub Actions).
 * Auth: X-Api-Key (ingest_api_key).
 *
 *   POST {items: [ ...VtexMapper::toArray()... ]}
 *   → upsert en wm_products + registra bajas ≥30% en wm_drops.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Walmart\WalmartWatch;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false, 'error' => 'Sólo POST']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) {
    out(400, ['ok' => false, 'error' => 'Payload inválido (se espera {items:[...]})']);
}

try {
    $res = (new WalmartWatch($db))->ingestBatch($in['items']);
    out(200, ['ok' => true] + $res);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
