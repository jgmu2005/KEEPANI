<?php
declare(strict_types=1);

/**
 * GET /api/groups.php — PÚBLICO.
 * Lista los grupos multi-tienda para la vitrina "Comparador" del dashboard.
 *   ?limit=N&offset=M
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo   = new ProductRepository(Db::conn());
    $limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 24;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $sort   = ($_GET['sort'] ?? 'discrepancy') === 'stores' ? 'stores' : 'discrepancy';
    $res    = $repo->groupsList($limit, $offset, $sort);

    echo json_encode(
        ['ok' => true, 'total' => $res['total'], 'limit' => $limit, 'offset' => $offset, 'items' => $res['items']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
