<?php
declare(strict_types=1);

/** POST /api/auth/reset.php { token, password } — fija la nueva contraseña. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$token = (string) ($in['token'] ?? '');
$pass  = (string) ($in['password'] ?? '');
$ip = RateLimiter::clientIp();

if (!RateLimiter::allow($db, $ip, 'reset', 10, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá un rato.']);
    exit;
}
if (strlen($pass) < 8) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres.']);
    exit;
}

$st = $db->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
$st->execute([$token]);
$id = $st->fetchColumn();

if ($id === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El enlace venció o no es válido. Pedí uno nuevo.']);
    exit;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
$db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
   ->execute([$hash, (int) $id]);

echo json_encode(['ok' => true]);
