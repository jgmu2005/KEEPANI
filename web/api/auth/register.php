<?php
declare(strict_types=1);

/** POST /api/auth/register.php  { email, password } → crea cuenta e inicia sesión. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usá POST']);
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

try {
    $user = Auth::register(Db::conn(), (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    echo json_encode(['ok' => true, 'user' => $user]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
