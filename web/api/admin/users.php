<?php
declare(strict_types=1);

/** GET /api/admin/users.php — ADMIN. Lista de usuarios con su nivel y # de alertas. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$sql = 'SELECT u.id, u.email, u.tier, u.donated_at, u.created_at,
               COUNT(a.id) AS alerts
          FROM users u
          LEFT JOIN alerts a ON a.user_id = u.id AND a.is_active = 1
         GROUP BY u.id
         ORDER BY u.created_at DESC
         LIMIT 500';

$users = array_map(static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'email'      => $r['email'],
        'tier'       => $r['tier'],
        'alerts'     => (int) $r['alerts'],
        'donated_at' => $r['donated_at'],
        'created_at' => $r['created_at'],
    ];
}, $db->query($sql)->fetchAll());

echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
