<?php
declare(strict_types=1);

/** POST /api/admin/user_tier.php — ADMIN. Cambia el nivel de un usuario.
 *  Body: { user_id, tier }  (tier: 'free' | 'onetime' | 'subscriber')
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$userId = (int) ($in['user_id'] ?? 0);
$tier   = (string) ($in['tier'] ?? '');

if (!$userId || !in_array($tier, Auth::TIERS, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos (user_id / tier)']);
    exit;
}

// free: limpia suscripción. onetime/subscriber: sella donación; el manual queda
// permanente (subscription_until NULL = sin vencimiento).
if ($tier === 'free') {
    $sql = 'UPDATE users SET tier = ?, subscription_until = NULL WHERE id = ?';
} else {
    $sql = 'UPDATE users SET tier = ?, subscription_until = NULL, donated_at = COALESCE(donated_at, NOW()) WHERE id = ?';
}
$db->prepare($sql)->execute([$tier, $userId]);

echo json_encode(['ok' => true]);
