<?php
declare(strict_types=1);

/** POST /api/auth/login.php  { email, password } */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usá POST']);
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

$db = Db::conn();
$ip = RateLimiter::clientIp();

// Máx. 15 intentos por IP cada 10 min (anti fuerza bruta).
if (!RateLimiter::allow($db, $ip, 'login', 15, 600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.']);
    exit;
}

try {
    $user = Auth::login($db, (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    echo json_encode(['ok' => true, 'user' => $user]);
} catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
