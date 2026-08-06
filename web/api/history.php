<?php
declare(strict_types=1);

/**
 * GET /api/history.php — PÚBLICO (sin registro).
 * Devuelve la ficha del producto + su histórico de precios para la gráfica.
 *
 * Formas de pedirlo (cualquiera):
 *   ?product_id=1
 *   ?store=sinsa&sku=140513297
 *   ?url=https://www.sinsa.com.ni/...-140513297/p   (pegar la URL del producto)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\DealAnalyzer;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // lectura pública (dashboard + extensión)

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $repo = new ProductRepository(Db::conn());

    $id = $repo->resolve(
        isset($_GET['product_id']) ? (int) $_GET['product_id'] : null,
        $_GET['store'] ?? null,
        $_GET['sku']   ?? null,
        $_GET['url']   ?? null,
    );

    if ($id === null) {
        out(404, ['ok' => false, 'error' => 'Producto no encontrado o aún no rastreado']);
    }

    $product = $repo->product($id);
    $history = $repo->history($id);

    $last = end($history) ?: null;
    $deal = DealAnalyzer::analyze(
        $last['price_final'] ?? null,
        $last['list_price']  ?? null,
        array_map(static fn(array $h) => $h['price_final'], $history)
    );

    out(200, [
        'ok'      => true,
        'product' => $product,
        'stats'   => $repo->stats($history),
        'history' => $history,
        'deal'    => $deal,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
