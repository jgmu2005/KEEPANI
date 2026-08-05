<?php
declare(strict_types=1);

/** GET /api/alerts/unsubscribe.php?a=ALERTID&t=TOKEN — desactiva esa alerta.
 *  Token = HMAC(alertId, ingest_api_key) → no necesita guardar nada en la BD. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

$db  = Db::conn();
$cfg = Db::config();
$secret = (string) ($cfg['ingest_api_key'] ?? '');

$aid = (int) ($_GET['a'] ?? 0);
$t   = (string) ($_GET['t'] ?? '');
$ok  = false;

if ($aid > 0 && $secret !== '') {
    $expected = hash_hmac('sha256', 'unsub:' . $aid, $secret);
    if (hash_equals($expected, $t)) {
        $db->prepare('UPDATE alerts SET is_active = 0 WHERE id = ?')->execute([$aid]);
        $ok = true;
    }
}

header('Content-Type: text/html; charset=utf-8');
$home = htmlspecialchars(Verification::baseUrl() . '/');
echo '<!doctype html><html lang="es"><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width, initial-scale=1">'
   . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:70px 20px;color:#0f172a">'
   . ($ok
        ? '<h2 style="color:#16a34a">✅ Listo</h2><p>Ya no recibirás alertas de ese producto.</p>'
        : '<h2 style="color:#dc2626">Enlace inválido</h2><p>No pudimos procesar la baja.</p>')
   . '<p style="margin-top:20px"><a href="' . $home . '" style="color:#0369a1">Volver a Ojo al Precio</a></p>'
   . '</body></html>';
