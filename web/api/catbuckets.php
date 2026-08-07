<?php
declare(strict_types=1);

/**
 * GET /api/catbuckets.php — PÚBLICO.
 * Categorías cross-store (por keywords del título) CON productos en stock, para
 * los chips del catálogo. Devuelve [{key,label,count}].
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $repo = new ProductRepository(Db::conn());
    echo json_encode(
        ['ok' => true, 'categories' => $repo->categoryBuckets()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
