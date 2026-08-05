<?php
declare(strict_types=1);

/** POST /api/auth/register.php  { email, password, website(honeypot) } */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\RateLimiter;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\Turnstile;

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

// Captcha Turnstile (si está configurado en config.php).
$tsSecret = (string) (Db::config()['turnstile_secret_key'] ?? '');
if (!Turnstile::verify($tsSecret, (string) ($in['turnstile_token'] ?? ''), $ip)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verificación anti-bot fallida. Recargá la página e intentá de nuevo.']);
    exit;
}

try {
    $user = Auth::register($db, (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    $sent = Verification::issueAndSend($db, $user['id'], $user['email']);
    if (!$sent) {
        // Sin SMTP no hay forma de verificar → auto-verificar para no bloquear la cuenta.
        $db->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')->execute([$user['id']]);
        $user['is_verified'] = true;
    }
    echo json_encode(['ok' => true, 'user' => $user, 'verify_sent' => $sent]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
