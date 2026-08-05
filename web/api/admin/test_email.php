<?php
declare(strict_types=1);

/** POST /api/admin/test_email.php — ADMIN. Envía un correo de prueba al admin. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Mailer;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$user = Auth::requireAdmin($db);

$mailer = Mailer::fromSettings($db);
if (!$mailer) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Configurá y guardá primero el SMTP (host, usuario y contraseña).']);
    exit;
}

$html = '<div style="font-family:system-ui,sans-serif;max-width:480px">'
    . '<h2 style="color:#0369a1">✅ ¡Funciona!</h2>'
    . '<p>Tu configuración SMTP de <b>Ojo al Precio</b> está correcta. '
    . 'Ya podés recibir alertas cuando baje el precio de un producto.</p></div>';

$res = $mailer->send($user['email'], 'Correo de prueba · Ojo al Precio', $html);

if ($res['ok']) {
    echo json_encode(['ok' => true, 'sent_to' => $user['email']]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Error al enviar']);
}
