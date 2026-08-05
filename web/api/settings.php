<?php
declare(strict_types=1);

/** GET /api/settings.php — PÚBLICO. Ajustes de branding para el sitio. */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    echo json_encode(['ok' => true, 'settings' => Settings::publicView(Db::conn())],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
