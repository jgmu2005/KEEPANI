<?php
declare(strict_types=1);

/** GET /api/admin/settings_get.php — ADMIN. Ajustes (branding + SMTP, pass enmascarada). */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Settings;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

echo json_encode(['ok' => true, 'settings' => Settings::adminView($db)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
