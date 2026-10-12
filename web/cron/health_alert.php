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

    // Bajada de conteo: tiendas que HOY refrescaron bastante menos que su mediana
    // habitual (14d) → posible corrida truncada (ingestó, pero trajo de menos).
    $low = [];
    try {
        $todayCnt = [];
        foreach ($db->query(
            "SELECT store_id, COUNT(*) c FROM products
              WHERE is_active = 1 AND last_date = CURDATE() GROUP BY store_id"
        ) as $r) { $todayCnt[(int) $r['store_id']] = (int) $r['c']; }

        $hist = []; $names = [];
        foreach ($db->query(
            "SELECT cc.store_id, cc.updated, s.name
               FROM crawl_counts cc JOIN stores s ON s.id = cc.store_id
              WHERE cc.day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND cc.day < CURDATE()"
        )->fetchAll() as $r) {
            $hist[(int) $r['store_id']][] = (int) $r['updated'];
            $names[(int) $r['store_id']]  = $r['name'];
        }
        $median = static function (array $xs): ?float {
            if (!$xs) { return null; }
            sort($xs); $n = count($xs); $m = intdiv($n, 2);
            return $n % 2 ? (float) $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
        };
        foreach ($hist as $sid => $days) {
            if (count($days) < 4) { continue; }
            $med = $median($days);
            if ($med !== null && $med >= 20) {
                $t = $todayCnt[$sid] ?? 0;
                if ($t < 0.70 * $med) {
                    $low[] = ['name' => $names[$sid] ?? ('#' . $sid), 'today' => $t, 'baseline' => (int) round($med)];
                }
            }
        }
        usort($low, static fn($a, $b) => ($a['today'] / max(1, $a['baseline'])) <=> ($b['today'] / max(1, $b['baseline'])));
    } catch (\Throwable $e) { /* crawl_counts aún no migrada: sin alerta de bajada */ }

    if (!$stale && !$low) {
        out(200, ['ok' => true, 'stale' => 0, 'low' => 0, 'sent' => false]);
    }

    usort($stale, static fn($a, $b) => $b['hours'] <=> $a['hours']);

    // Destinatario: correo del sitio (from) o el usuario SMTP.
    $to = Settings::get($db, 'smtp_from_email') ?: Settings::get($db, 'smtp_user');
    $mailer = Mailer::fromSettings($db);
    if (!$to || !$mailer) {
        out(200, ['ok' => true, 'stale' => count($stale), 'sent' => false, 'note' => 'SMTP no configurado; no se envió correo', 'stores' => $stale]);
    }

    $html = '<div style="font-family:system-ui,sans-serif;max-width:560px">';
    if ($stale) {
        $li = '';
        foreach ($stale as $s) {
            $d = intdiv($s['hours'], 24); $rem = $s['hours'] % 24;
            $ago = $d > 0 ? "{$d}d {$rem}h" : "{$s['hours']}h";
            $li .= '<li><b>' . htmlspecialchars($s['name']) . '</b> — sin datos hace ' . $ago
                 . ' <span style="color:#64748b">(última: ' . htmlspecialchars((string) $s['last']) . ')</span></li>';
        }
        $html .= '<h2 style="color:#b91c1c">⚠️ Crawl atrasado</h2>'
            . '<p>Estas tiendas llevan más de ' . $hours . 'h sin ingestar productos:</p><ul>' . $li . '</ul>';
    }
    if ($low) {
        $li2 = '';
        foreach ($low as $s) {
            $pct = $s['baseline'] > 0 ? (int) round(100 * $s['today'] / $s['baseline']) : 0;
            $li2 .= '<li><b>' . htmlspecialchars($s['name']) . '</b> — refrescó <b>' . number_format($s['today'])
                  . '</b> hoy vs ~' . number_format($s['baseline']) . ' habitual '
                  . '<span style="color:#9a3412">(' . $pct . '%)</span></li>';
        }
        $html .= '<h2 style="color:#9a3412">📉 Refrescaron de menos</h2>'
            . '<p>Estas tiendas ingestaron, pero trajeron bastante MENOS productos que lo habitual '
            . '(posible corrida truncada — el panel saldría verde igual):</p><ul>' . $li2 . '</ul>';
    }
    $html .= '<p style="color:#64748b;font-size:.9rem">Revisá el crawler de esas tiendas '
        . '(Actions, cron server-side o la PC local).</p></div>';

    $parts = [];
    if ($stale) { $parts[] = count($stale) . ' sin datos'; }
    if ($low)   { $parts[] = count($low) . ' de menos'; }
    $res = $mailer->send($to, '⚠️ Ojo al Precio — ' . implode(' · ', $parts), $html);

    out(200, [
        'ok'           => true,
        'stale'        => count($stale),
        'low'          => count($low),
        'sent'         => (bool) ($res['ok'] ?? false),
        'to'           => $to,
        'stores_stale' => $stale,
        'stores_low'   => $low,
        'error'        => ($res['ok'] ?? false) ? null : ($res['error'] ?? 'error al enviar'),
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
