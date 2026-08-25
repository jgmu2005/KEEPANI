<?php
declare(strict_types=1);

/**
 * CRON — alerta por correo si alguna tienda dejó de ingestar (crawl caído).
 * Compara la última ingesta (MAX last_seen_at de productos activos) por tienda; si
 * alguna que ANTES ingestaba lleva más de STALE_HOURS sin datos, manda un correo al
 * admin (smtp_from_email / smtp_user). Pensado para correr 1×/día (cron-job.org).
 *
 *   GET /cron/health_alert.php            Header: X-Api-Key: <ingest_api_key>
 *   GET /cron/health_alert.php?hours=48   (umbral configurable)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Mailer;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

try {
    $db  = Db::conn();
    $cfg = Db::config();
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $cfg['ingest_api_key'] ?? '';
    if ($expected === '' || !is_string($key) || !hash_equals($expected, $key)) {
        out(401, ['ok' => false, 'error' => 'No autorizado']);
    }

    try { $db->exec('SET SQL_BIG_SELECTS=1'); } catch (\Throwable $e) {}

    $hours = max(6, min((int) ($_GET['hours'] ?? 48), 720));

    // Última ingesta por tienda (sólo activos).
    $rows = $db->query(
        "SELECT s.name, MAX(p.last_seen_at) AS last_ingest
           FROM stores s LEFT JOIN products p ON p.store_id = s.id AND p.is_active = 1
          GROUP BY s.id, s.name"
    )->fetchAll();

    $now = time();
    $stale = [];
    foreach ($rows as $r) {
        if ($r['last_ingest'] === null) { continue; } // tienda nueva/sin crawl aún: no alerta
        $h = (int) floor(($now - strtotime((string) $r['last_ingest'])) / 3600);
        if ($h >= $hours) { $stale[] = ['name' => $r['name'], 'hours' => $h, 'last' => $r['last_ingest']]; }
    }

    if (!$stale) {
        out(200, ['ok' => true, 'stale' => 0, 'sent' => false]);
    }

    usort($stale, static fn($a, $b) => $b['hours'] <=> $a['hours']);

    // Destinatario: correo del sitio (from) o el usuario SMTP.
    $to = Settings::get($db, 'smtp_from_email') ?: Settings::get($db, 'smtp_user');
    $mailer = Mailer::fromSettings($db);
    if (!$to || !$mailer) {
        out(200, ['ok' => true, 'stale' => count($stale), 'sent' => false, 'note' => 'SMTP no configurado; no se envió correo', 'stores' => $stale]);
    }

    $li = '';
    foreach ($stale as $s) {
        $d = intdiv($s['hours'], 24); $rem = $s['hours'] % 24;
        $ago = $d > 0 ? "{$d}d {$rem}h" : "{$s['hours']}h";
        $li .= '<li><b>' . htmlspecialchars($s['name']) . '</b> — sin datos hace ' . $ago
             . ' <span style="color:#64748b">(última: ' . htmlspecialchars((string) $s['last']) . ')</span></li>';
    }
    $html = '<div style="font-family:system-ui,sans-serif;max-width:520px">'
        . '<h2 style="color:#b91c1c">⚠️ Crawl atrasado</h2>'
        . '<p>Estas tiendas llevan más de ' . $hours . 'h sin ingestar productos en <b>Ojo al Precio</b>:</p>'
        . '<ul>' . $li . '</ul>'
        . '<p style="color:#64748b;font-size:.9rem">Revisá el crawler de esas tiendas (Actions, cron server-side o la PC local para telcmax).</p></div>';

    $res = $mailer->send($to, '⚠️ Ojo al Precio — ' . count($stale) . ' tienda(s) sin datos', $html);

    out(200, [
        'ok'     => true,
        'stale'  => count($stale),
        'sent'   => (bool) ($res['ok'] ?? false),
        'to'     => $to,
        'stores' => $stale,
        'error'  => $res['ok'] ?? false ? null : ($res['error'] ?? 'error al enviar'),
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
