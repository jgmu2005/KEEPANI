<?php
declare(strict_types=1);

/**
 * GET /api/products.php — PÚBLICO.
 * Lista los productos rastreados con su último precio (para el home del dashboard).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\DealAnalyzer;

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
        'cat_name' => $_GET['cat_name'] ?? null,
        'sort'     => $_GET['sort']     ?? 'name',
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

    // Adjunta la serie de precios (sparkline) a cada ítem, en una sola query.
    $ids    = array_map(static fn(array $it): int => $it['id'], $res['items']);
    $series = $repo->priceSeries($ids);
    foreach ($res['items'] as &$it) {
        $it['series'] = $series[$it['id']] ?? [];
        $it['deal']   = DealAnalyzer::analyze(
            $it['price_final'],
            $it['list_price'],
            array_map(static fn(array $s): float => (float) $s['p'], $it['series'])
        );
    }
    unset($it);

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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
