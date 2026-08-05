<?php
declare(strict_types=1);

/** GET /api/auth/me.php → usuario actual (o user:null si no hay sesión). */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(['ok' => true, 'user' => Auth::currentUser(Db::conn())]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
