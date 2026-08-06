<?php
declare(strict_types=1);

/**
 * GET /api/changes.php — PÚBLICO.
 * Productos cuyo precio cambió respecto a la captura anterior.
 * Va vacío hasta que haya ≥2 días de datos con precios distintos.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo  = new ProductRepository(Db::conn());
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 12;

    http_response_code(200);
    echo json_encode(
        ['ok' => true, 'items' => $repo->recentChanges($limit)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
