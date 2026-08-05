<?php
declare(strict_types=1);

/** POST /api/admin/settings_save.php — ADMIN. Guarda ajustes del sitio. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Settings;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

foreach ($in as $k => $v) {
    Settings::set($db, (string) $k, (string) $v); // set ignora claves fuera de la lista blanca
}

echo json_encode(['ok' => true, 'settings' => Settings::publicView($db)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
