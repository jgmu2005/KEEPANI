<?php
declare(strict_types=1);

/**
 * CRON de alertas — lo dispara cron-job.org después del refresco diario.
 *
 *   GET/POST /cron/alerts_check.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Para cada alerta activa: si el precio actual <= objetivo y aún no avisó,
 * manda el email y marca. Si el precio vuelve a subir por encima del objetivo,
 * re-arma la alerta (para que vuelva a avisar en la próxima bajada).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Alerts;
use OjoAlPrecio\Web\Mailer;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Mantenimiento diario: bajar a 'free' las suscripciones vencidas.
$db->exec("UPDATE users SET tier = 'free'
            WHERE tier = 'subscriber' AND subscription_until IS NOT NULL AND subscription_until < NOW()");

$mailer   = Mailer::fromSettings($db);
$siteName = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
$base = Verification::baseUrl();
$fmt = fn($v) => 'C$' . number_format((float) $v, 2);

$alerts = Alerts::allActiveWithPrice($db);
$emailed = 0; $rearmed = 0; $noPrice = 0; $pending = 0;

$restocked = 0;

foreach ($alerts as $a) {
    $type    = $a['alert_type'] ?? 'price';
    $already = $a['last_triggered_at'] !== null;

    // ---- Alerta de restock: dispara al pasar de agotado a disponible ----
    if ($type === 'restock') {
        $inStock = (int) $a['in_stock'] === 1;
        if ($inStock) {
            if ($already) { continue; }             // ya avisó y sigue en stock
            if (!$mailer)  { $pending++; continue; } // SMTP sin configurar → reintentar luego

            $unsubUrl = $base . '/api/alerts/unsubscribe.php?a=' . (int) $a['id']
                . '&t=' . hash_hmac('sha256', 'unsub:' . (int) $a['id'], (string) ($cfg['ingest_api_key'] ?? ''));
            $priceLine = $a['price'] !== null ? '<p style="font-size:1.2rem;margin:8px 0"><b>' . $fmt($a['price']) . '</b></p>' : '';

            $html = '<div style="font-family:system-ui,sans-serif;max-width:520px">'
                . '<h2 style="color:#16a34a;margin:0 0 8px">🎉 ¡Volvió a estar disponible!</h2>'
                . '<p style="margin:0 0 4px"><b>' . htmlspecialchars((string) $a['title']) . '</b></p>'
                . $priceLine
                . '<p><a href="' . htmlspecialchars((string) $a['url']) . '" '
                . 'style="background:#0ea5e9;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Ver el producto ↗</a></p>'
                . '<p style="color:#94a3b8;font-size:.8rem;margin-top:20px">Recibís esto por una alerta que creaste en ' . htmlspecialchars((string) $siteName) . '. '
                . '<a href="' . htmlspecialchars($unsubUrl) . '" style="color:#94a3b8">Dejar de recibir estas alertas</a>.</p></div>';

            $res = $mailer->send((string) $a['email'], '🎉 Volvió a estar disponible: ' . $a['title'], $html);
            if ($res['ok']) {
                Alerts::markTriggered($db, (int) $a['id'], (float) ($a['price'] ?? 0));
                $emailed++; $restocked++;
            } else {
                $pending++;
            }
        } elseif ($already) {
            Alerts::rearm($db, (int) $a['id']); $rearmed++; // se agotó de nuevo → re-armar
        }
        continue;
    }

    // ---- Alerta de precio ----
    if ($a['price'] === null) { $noPrice++; continue; }

    $price  = (float) $a['price'];
    $target = (float) $a['target_price'];

    if ($price <= $target) {
        if ($already) { continue; }            // ya avisó y sigue bajo → no re-spamear
        if (!$mailer) { $pending++; continue; } // SMTP sin configurar → reintentar luego

        $unsubUrl = $base . '/api/alerts/unsubscribe.php?a=' . (int) $a['id']
            . '&t=' . hash_hmac('sha256', 'unsub:' . (int) $a['id'], (string) ($cfg['ingest_api_key'] ?? ''));

        $html = '<div style="font-family:system-ui,sans-serif;max-width:520px">'
            . '<h2 style="color:#16a34a;margin:0 0 8px">📉 ¡Bajó de precio!</h2>'
            . '<p style="margin:0 0 4px"><b>' . htmlspecialchars((string) $a['title']) . '</b></p>'
            . '<p style="font-size:1.3rem;margin:8px 0"><b>' . $fmt($price) . '</b> '
            . '<span style="color:#64748b;font-size:.9rem">(tu objetivo: ' . $fmt($target) . ')</span></p>'
            . '<p><a href="' . htmlspecialchars((string) $a['url']) . '" '
            . 'style="background:#0ea5e9;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Ver el producto ↗</a></p>'
            . '<p style="color:#94a3b8;font-size:.8rem;margin-top:20px">Recibís esto por una alerta que creaste en ' . htmlspecialchars((string) $siteName) . '. '
            . '<a href="' . htmlspecialchars($unsubUrl) . '" style="color:#94a3b8">Dejar de recibir estas alertas</a>.</p></div>';

        $res = $mailer->send((string) $a['email'], '📉 Bajó de precio: ' . $a['title'], $html);
        if ($res['ok']) {
            Alerts::markTriggered($db, (int) $a['id'], $price);
            $emailed++;
        } else {
            $pending++; // no marcamos: se reintenta en la próxima corrida
        }
    } elseif ($already) {
        Alerts::rearm($db, (int) $a['id']);     // volvió a subir → re-armar
        $rearmed++;
    }
}

out(200, [
    'ok' => true,
    'checked'         => count($alerts),
    'emailed'         => $emailed,
    'restocked'       => $restocked,
    'rearmed'         => $rearmed,
    'no_price'        => $noPrice,
    'mail_pending'    => $pending,
    'mail_configured' => (bool) $mailer,
]);
