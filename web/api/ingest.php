<?php
declare(strict_types=1);

/**
 * POST /api/ingest  — recibe un batch normalizado del scraper y lo persiste.
 *
 * Autenticación: header  X-Api-Key: <ingest_api_key de config.php>
 * Body (JSON):
 *   { "captured_at": "...", "items": [ { store, sku, url, title, ... }, ... ] }
 *
 * Respuesta (JSON): { ok, received, inserted, updated, skipped, errors }
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Solo POST ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Método no permitido, usa POST']);
}

// --- Autenticación por API key (comparación en tiempo constante) ---
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || $expected === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA'
    || !is_string($sent) || !hash_equals($expected, $sent)) {
    respond(401, ['ok' => false, 'error' => 'No autorizado']);
}

// --- Parseo del body ---
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
    respond(400, ['ok' => false, 'error' => 'JSON inválido: falta items[]']);
}
if (count($body['items']) > 2000) {
    respond(413, ['ok' => false, 'error' => 'Batch demasiado grande (máx 2000 items)']);
}

// --- Persistir ---
try {
    $service = new IngestService(Db::conn());
    $result  = $service->ingest($body['items']);
    respond(200, $result);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
