<?php
declare(strict_types=1);

/** GET /api/wm_alerts/unsubscribe.php?u=USERID&t=TOKEN — desactiva las liquidaciones.
 *  Token = HMAC('wm:'+userId, ingest_api_key). No guarda tokens en la BD. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

$db  = Db::conn();
$cfg = Db::config();
$secret = (string) ($cfg['ingest_api_key'] ?? '');

$uid = (int) ($_GET['u'] ?? 0);
$t   = (string) ($_GET['t'] ?? '');
$ok  = false;

if ($uid > 0 && $secret !== '') {
    $expected = hash_hmac('sha256', 'wm:' . $uid, $secret);
    if (hash_equals($expected, $t)) {
        $db->prepare('UPDATE users SET wm_alerts = 0 WHERE id = ?')->execute([$uid]);
        $ok = true;
    }
}

header('Content-Type: text/html; charset=utf-8');
$home = htmlspecialchars(Verification::baseUrl() . '/');
echo '<!doctype html><html lang="es"><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width, initial-scale=1">'
   . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:70px 20px;color:#0f172a">'
   . ($ok
        ? '<h2 style="color:#16a34a">✅ Listo</h2><p>Ya no recibirás los emails de liquidaciones de Walmart. Seguís teniendo tus otras alertas.</p>'
        : '<h2 style="color:#dc2626">Enlace inválido</h2><p>No pudimos procesar la baja.</p>')
   . '<p style="margin-top:20px"><a href="' . $home . '" style="color:#0369a1">Volver a Ojo al Precio</a></p>'
   . '</body></html>';
