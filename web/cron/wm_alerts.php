<?php
declare(strict_types=1);

/**
 * CRON — email de LIQUIDACIONES de Walmart. PERK EXCLUSIVO de suscriptores mensuales.
 *   GET/POST /cron/wm_alerts.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Junta las bajas ≥30% nuevas (sin notificar) y manda UN digest a cada usuario
 * con tier='subscriber' (verificado y con la preferencia wm_alerts activa). Luego
 * marca esas bajas como notificadas. Lo dispara cron-job.org tras el crawl.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Mailer;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\Walmart\WalmartWatch;

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

$repo  = new WalmartWatch($db);
$drops = $repo->pendingDrops(40);
if (!$drops) {
    out(200, ['ok' => true, 'pending' => 0, 'emailed' => 0]);
}

$mailer = Mailer::fromSettings($db);
if (!$mailer) {
    // Sin SMTP: no marcamos, reintentamos en la próxima corrida.
    out(200, ['ok' => true, 'pending' => count($drops), 'emailed' => 0, 'note' => 'SMTP sin configurar']);
}

$siteName = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
$base     = rtrim(Verification::baseUrl(), '/');
$key      = (string) $cfg['ingest_api_key'];
$total    = $repo->pendingCount();
$more     = max(0, $total - count($drops));

// Suscriptores mensuales activos y verificados, con la preferencia activada
// (y su filtro de categorías de interés).
$subs = $db->query(
    "SELECT id, email, notif_cats FROM users
      WHERE tier = 'subscriber' AND is_verified = 1 AND wm_alerts = 1
        AND (subscription_until IS NULL OR subscription_until >= NOW())"
)->fetchAll();

$fmt = static fn($v, $c) => ($c === 'USD' ? 'US$' : 'C$') . number_format((float) $v, 2);

// Arma la tabla HTML para un subconjunto de bajas.
$rowsFor = static function (array $list) use ($fmt): string {
    $rows = '';
    foreach ($list as $d) {
        $ref = ((float) $d['ref_price'] > (float) $d['new_price']) ? $d['ref_price'] : $d['old_price'];
        $rows .= '<tr>'
            . '<td style="padding:8px 6px;border-bottom:1px solid #eee">'
            . '<a href="' . htmlspecialchars((string) $d['url']) . '" style="color:#0369a1;text-decoration:none;font-weight:600">' . htmlspecialchars((string) ($d['title'] ?: 'Producto')) . '</a></td>'
            . '<td style="padding:8px 6px;border-bottom:1px solid #eee;white-space:nowrap;text-align:right">'
            . '<b style="color:#16a34a">' . $fmt($d['new_price'], $d['currency']) . '</b> '
            . '<s style="color:#94a3b8;font-size:.85em">' . $fmt($ref, $d['currency']) . '</s></td>'
            . '<td style="padding:8px 6px;border-bottom:1px solid #eee;text-align:right;color:#dc2626;font-weight:800">-' . (int) round($d['pct']) . '%</td>'
            . '</tr>';
    }
    return $rows;
};

$emailed = 0;
foreach ($subs as $u) {
    // Filtro por categorías de interés del usuario (vacío = todas).
    $cats = array_filter(array_map('trim', explode(',', (string) $u['notif_cats'])));
    $mine = $cats
        ? array_values(array_filter($drops, static fn($d) => in_array($d['cat_key'], $cats, true)))
        : $drops;
    if (!$mine) { continue; } // nada en sus categorías → no le mandamos

    $unsub = $base . '/api/wm_alerts/unsubscribe.php?u=' . (int) $u['id'] . '&t=' . hash_hmac('sha256', 'wm:' . (int) $u['id'], $key);
    $html = '<div style="font-family:system-ui,sans-serif;max-width:600px">'
        . '<h2 style="color:#dc2626;margin:0 0 4px">🔥 Liquidaciones de Walmart</h2>'
        . '<p style="color:#475569;margin:0 0 14px">Nuevas bajas de precio ≥30% (perk de tu suscripción):</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $rowsFor($mine) . '</table>'
        . '<p style="margin:14px 0"><a href="' . htmlspecialchars($base) . '/liquidaciones" style="color:#0369a1">Ver todas las liquidaciones ↗</a></p>'
        . '<p style="color:#94a3b8;font-size:.8rem;margin-top:22px">Recibís esto por tu suscripción a ' . htmlspecialchars($siteName) . '. '
        . 'Precios referenciales; verificá en Walmart antes de comprar. '
        . '<a href="' . htmlspecialchars($unsub) . '" style="color:#94a3b8">Dejar de recibir liquidaciones</a>.</p></div>';
    $res = $mailer->send((string) $u['email'], '🔥 ' . count($mine) . ' liquidaciones nuevas en Walmart', $html);
    if ($res['ok']) { $emailed++; }
}

$repo->markAllNotified();

out(200, ['ok' => true, 'pending' => count($drops), 'subscribers' => count($subs), 'emailed' => $emailed]);
