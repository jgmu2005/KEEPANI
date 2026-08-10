<?php
declare(strict_types=1);

/** POST /api/account/delete.php {password} — elimina la cuenta del usuario (con sesión).
 *  Borra el usuario; alertas y notificaciones caen por ON DELETE CASCADE. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

$in  = json_decode(file_get_contents('php://input') ?: '', true);
$pw  = is_array($in) ? (string) ($in['password'] ?? '') : '';

$st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
$st->execute([$u['id']]);
$hash = (string) $st->fetchColumn();

if ($hash === '' || !password_verify($pw, $hash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Contraseña incorrecta.']);
    exit;
}

// Desvincula donaciones (registro de pago se conserva, sin PII de usuario).
$db->prepare('UPDATE donations SET matched_user_id = NULL WHERE matched_user_id = ?')->execute([$u['id']]);
$db->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);

Auth::logout();
echo json_encode(['ok' => true]);
