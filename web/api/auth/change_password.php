<?php
declare(strict_types=1);

/** POST /api/auth/change_password.php {current, new} — cambia la contraseña (con sesión). */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

$in  = json_decode(file_get_contents('php://input') ?: '', true);
$cur = is_array($in) ? (string) ($in['current'] ?? '') : '';
$new = is_array($in) ? (string) ($in['new'] ?? '') : '';

if (strlen($new) < 8) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres.']);
    exit;
}

$st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$st->execute([$u['id']]);
$hash = (string) $st->fetchColumn();

if (!password_verify($cur, $hash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La contraseña actual no es correcta.']);
    exit;
}

$db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
   ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);

echo json_encode(['ok' => true]);
