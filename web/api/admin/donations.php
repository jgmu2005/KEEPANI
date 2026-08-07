<?php
declare(strict_types=1);

/** GET /api/admin/donations.php — ADMIN. Últimas donaciones recibidas. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$sql = 'SELECT d.id, d.email, d.from_name, d.amount, d.currency, d.type,
               d.matched_user_id, d.created_at, u.email AS matched_email
          FROM donations d
          LEFT JOIN users u ON u.id = d.matched_user_id
         ORDER BY d.created_at DESC
         LIMIT 200';

$rows = array_map(static function (array $r): array {
    return [
        'id'            => (int) $r['id'],
        'email'         => $r['email'],
        'from_name'     => $r['from_name'],
        'amount'        => $r['amount'] !== null ? (float) $r['amount'] : null,
        'currency'      => $r['currency'],
        'type'          => $r['type'],
        'matched_email' => $r['matched_email'],
        'created_at'    => $r['created_at'],
    ];
}, $db->query($sql)->fetchAll());

echo json_encode(['ok' => true, 'donations' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
