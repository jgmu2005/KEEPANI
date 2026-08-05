<?php
declare(strict_types=1);

/**
 * GET /api/products.php — PÚBLICO.
 * Lista los productos rastreados con su último precio (para el home del dashboard).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo = new ProductRepository(Db::conn());
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;

    http_response_code(200);
    echo json_encode(
        ['ok' => true, 'items' => $repo->listWithLatest($limit)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
