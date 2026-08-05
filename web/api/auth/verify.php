<?php
declare(strict_types=1);

/** GET /api/auth/verify.php?token=XXX — link del correo. Verifica y redirige al sitio. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

$db = Db::conn();
$token = (string) ($_GET['token'] ?? '');
$ok = false;

if ($token !== '') {
    $st = $db->prepare('SELECT id FROM users WHERE verify_token = ? LIMIT 1');
    $st->execute([$token]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $db->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')->execute([$id]);
        $ok = true;
    }
}

header('Location: ' . Verification::baseUrl() . '/?verified=' . ($ok ? '1' : '0'));
