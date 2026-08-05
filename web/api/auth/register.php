<?php
declare(strict_types=1);

/** POST /api/auth/register.php  { email, password, website(honeypot) } */

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

// Honeypot: campo oculto que solo llenan los bots.
if (!empty($in['website'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se pudo procesar el registro.']);
    exit;
}

$db = Db::conn();
$ip = RateLimiter::clientIp();

// Máx. 5 registros por IP por hora.
if (!RateLimiter::allow($db, $ip, 'register', 5, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados registros desde tu red. Probá de nuevo en un rato.']);
    exit;
}

try {
    $user = Auth::register($db, (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    echo json_encode(['ok' => true, 'user' => $user]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
