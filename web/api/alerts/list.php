<?php
declare(strict_types=1);

/** GET /api/alerts/list.php — usuario. Sus alertas + tope del nivel. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Alerts;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

echo json_encode([
    'ok'     => true,
    'alerts' => Alerts::listForUser($db, $u['id']),
    'limit'  => $u['alert_limit'],
    'tier'   => $u['tier'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
