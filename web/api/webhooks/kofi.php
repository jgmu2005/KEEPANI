<?php
declare(strict_types=1);

/**
 * POST /api/webhooks/kofi.php — lo llama Ko-fi cuando alguien dona.
 *
 * Ko-fi manda application/x-www-form-urlencoded con un campo `data` (JSON):
 *   { verification_token, message_id, type, from_name, message, amount,
 *     currency, email, ... }
 *
 * Verifica el token, guarda la donación (idempotente por message_id) y sube al
 * usuario a "Donante" (match por el correo de la donación, o un correo en el
 * mensaje). Si no hay match, queda registrada para aprobarla a mano en el admin.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;

header('Content-Type: text/plain; charset=utf-8');

$db = Db::conn();

$raw  = $_POST['data'] ?? '';
$data = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo 'bad payload';
    exit;
}

// Verificación (token que copiás de Ko-fi → guardado en el admin).
$expected = Settings::get($db, 'kofi_token');
if ($expected === '' || (string) ($data['verification_token'] ?? '') !== $expected) {
    http_response_code(403);
    echo 'unauthorized';
    exit;
}

$msgId = (string) ($data['message_id'] ?? ($data['kofi_transaction_id'] ?? ''));
if ($msgId === '') {
    http_response_code(400);
    echo 'no id';
    exit;
}

$email = strtolower(trim((string) ($data['email'] ?? '')));

// Guardar (idempotente: INSERT IGNORE por (source, external_id)).
$ins = $db->prepare(
    'INSERT IGNORE INTO donations (source, external_id, email, from_name, amount, currency, message, type)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$ins->execute([
    'kofi', $msgId, $email ?: null,
    $data['from_name'] ?? null,
    isset($data['amount']) ? (float) $data['amount'] : null,
    $data['currency'] ?? null,
    $data['message'] ?? null,
    $data['type'] ?? null,
]);
if ($ins->rowCount() === 0) {
    echo 'ok (repetido)'; // ya procesada
    exit;
}

// Acreditar a un usuario.
$userId = matchUser($db, $email, (string) ($data['message'] ?? ''));
if ($userId) {
    $isSub = ($data['type'] ?? '') === 'Subscription' || !empty($data['is_subscription_payment']);
    if ($isSub) {
        // Suscripción: ilimitado. Vigencia +34 días (se renueva con cada pago).
        $db->prepare("UPDATE users SET tier = 'subscriber', subscription_until = (NOW() + INTERVAL 34 DAY), donated_at = COALESCE(donated_at, NOW()) WHERE id = ?")
           ->execute([$userId]);
    } else {
        // Un pago: sube a 'onetime' (sin bajar a un suscriptor activo).
        $db->prepare("UPDATE users SET tier = IF(tier = 'subscriber', 'subscriber', 'onetime'), donated_at = COALESCE(donated_at, NOW()) WHERE id = ?")
           ->execute([$userId]);
    }
    $db->prepare('UPDATE donations SET matched_user_id = ? WHERE source = ? AND external_id = ?')
       ->execute([$userId, 'kofi', $msgId]);
}

echo 'ok';

/** Busca el usuario por el correo de la donación; si no, por un correo en el mensaje. */
function matchUser(\PDO $db, string $email, string $message): ?int
{
    $find = static function (\PDO $db, string $e): ?int {
        $s = $db->prepare('SELECT id FROM users WHERE email = ?');
        $s->execute([strtolower(trim($e))]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    };

    if ($email !== '' && ($id = $find($db, $email)) !== null) {
        return $id;
    }
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $message, $m)) {
        if (($id = $find($db, $m[0])) !== null) {
            return $id;
        }
    }
    return null;
}
