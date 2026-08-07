<?php
declare(strict_types=1);

/**
 * GET /api/changes.php — PÚBLICO. Cambios de precio vs la captura anterior.
 *   ?mode=per_store            → la baja más fuerte de cada tienda (home)
 *   ?sort=drop|rise&limit&offset → todos los cambios ordenables (página completa)
 *   (sin params)               → recientes (compat)
 * Va vacío hasta que haya ≥2 días de datos con precios distintos.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo = new ProductRepository(Db::conn());
    $mode = $_GET['mode'] ?? '';

    if ($mode === 'per_store') {
        $items = $repo->topChangePerStore();
    } elseif (isset($_GET['sort'])) {
        $sort   = $_GET['sort'] === 'rise' ? 'rise' : 'drop';
        $limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 24;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $items  = $repo->changesList($sort, $limit, $offset);
    } else {
        $items = $repo->recentChanges(isset($_GET['limit']) ? (int) $_GET['limit'] : 12);
    }

    http_response_code(200);
    echo json_encode(
        ['ok' => true, 'items' => $items],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
