<?php
declare(strict_types=1);

/** POST /api/auth/resend.php — reenvía el correo de verificación (rate-limited). */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\RateLimiter;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

if ($u['is_verified']) {
    echo json_encode(['ok' => true, 'already' => true]);
    exit;
}

$ip = RateLimiter::clientIp();
if (!RateLimiter::allow($db, $ip, 'resend', 3, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Ya enviamos varios correos. Esperá un rato y revisá tu bandeja (y spam).']);
    exit;
}

$sent = Verification::issueAndSend($db, $u['id'], $u['email']);
echo json_encode(['ok' => $sent, 'error' => $sent ? null : 'No se pudo enviar el correo en este momento.']);
