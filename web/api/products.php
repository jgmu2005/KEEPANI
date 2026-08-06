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
    $repo   = new ProductRepository(Db::conn());
    $limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 50;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

    $res = $repo->search([
        'q'        => $_GET['q']        ?? null,
        'store'    => $_GET['store']    ?? null,
        'min'      => $_GET['min']      ?? null,
        'max'      => $_GET['max']      ?? null,
        'in_stock' => $_GET['in_stock'] ?? null,
        'category' => $_GET['category'] ?? null,
        'sort'     => $_GET['sort']     ?? 'name',
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

    http_response_code(200);
    echo json_encode(
        [
            'ok'     => true,
            'total'  => $res['total'],
            'limit'  => $limit,
            'offset' => $offset,
            'items'  => $res['items'],
            'stores' => $repo->activeStores(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
