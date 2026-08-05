<?php
declare(strict_types=1);

/** POST /api/auth/forgot.php { email } — envía link para restablecer contraseña. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Mailer;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\RateLimiter;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$email = strtolower(trim((string) ($in['email'] ?? '')));
$ip = RateLimiter::clientIp();

// Máx. 3 solicitudes por IP por hora.
if (!RateLimiter::allow($db, $ip, 'recover', 3, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiadas solicitudes. Esperá un rato.']);
    exit;
}

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $id = $st->fetchColumn();

    if ($id !== false) {
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE users SET reset_token = ?, reset_expires = (NOW() + INTERVAL 1 HOUR) WHERE id = ?')
           ->execute([$token, (int) $id]);

        $mailer = Mailer::fromSettings($db);
        if ($mailer) {
            $site = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
            $url  = Verification::baseUrl() . '/reset.html?token=' . $token;
            $html = '<div style="font-family:system-ui,sans-serif;max-width:480px">'
                . '<h2 style="color:#0369a1">Restablecer contraseña</h2>'
                . '<p>Pediste restablecer tu contraseña en <b>' . htmlspecialchars((string) $site) . '</b>. '
                . 'Tocá el botón (el enlace vence en 1 hora):</p>'
                . '<p><a href="' . htmlspecialchars($url) . '" '
                . 'style="background:#0ea5e9;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">Elegir nueva contraseña</a></p>'
                . '<p style="color:#94a3b8;font-size:.8rem;margin-top:18px">Si no fuiste vos, ignorá este correo; tu contraseña no cambia.</p></div>';
            $mailer->send($email, 'Restablecer contraseña · ' . $site, $html);
        }
    }
}

// Respuesta SIEMPRE genérica (no revela si el correo existe).
echo json_encode(['ok' => true]);
