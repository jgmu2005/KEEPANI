<?php
declare(strict_types=1);

/** POST /api/auth/logout.php → cierra la sesión. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::logout();
echo json_encode(['ok' => true]);
