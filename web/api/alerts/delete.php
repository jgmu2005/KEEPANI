<?php
declare(strict_types=1);

/** POST /api/alerts/delete.php — usuario. { id } → borra su alerta. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Alerts;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id = (int) ($in['id'] ?? 0);

if ($id > 0) {
    Alerts::delete($db, $u['id'], $id);
}
echo json_encode(['ok' => true]);
