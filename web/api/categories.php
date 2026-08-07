<?php
declare(strict_types=1);

/** GET /api/categories.php?store=slug — PÚBLICO. Categorías con productos de una tienda. */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo  = new ProductRepository(Db::conn());
    $store = (string) ($_GET['store'] ?? '');
    $cats  = $store !== '' ? $repo->categoriesWithProducts($store) : [];
    echo json_encode(['ok' => true, 'categories' => $cats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
