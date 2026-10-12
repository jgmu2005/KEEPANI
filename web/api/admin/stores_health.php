<?php
declare(strict_types=1);

/**
 * GET /api/admin/stores_health.php — ADMIN. Salud de crawl por tienda:
 * productos activos, última ingesta (frescura), entradas (nuevos) y salidas
 * (desactivados) de hoy y de los últimos 7 días. Alimenta el panel "Tiendas".
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {

$db = Db::conn();
Auth::requireAdmin($db);

// FatCow aborta joins grandes por MAX_JOIN_SIZE → habilitar para esta sesión.
try { $db->exec('SET SQL_BIG_SELECTS=1'); } catch (\Throwable $e) {}

$rows = static function (string $sql) use ($db): array {
    try { return $db->query($sql)->fetchAll(); } catch (\Throwable $e) { return []; }
};

// Umbrales de frescura (horas): a las 24h "atrasada un día" (amarillo), a las 48h
// "sin datos" (rojo, probablemente rota).
$STALE_HOURS = 48;
$WARN_HOURS  = 24;

// 1) Activos + última ingesta por tienda (LEFT JOIN → incluye tiendas sin activos).
$base = [];
foreach ($rows(
    "SELECT s.id, s.slug, s.name, s.platform,
            COUNT(p.id) AS active,
            MAX(p.last_seen_at) AS last_ingest
       FROM stores s
       LEFT JOIN products p ON p.store_id = s.id AND p.is_active = 1
      GROUP BY s.id, s.slug, s.name, s.platform"
) as $r) {
    $base[(int) $r['id']] = [
        'slug'        => $r['slug'],
        'name'        => $r['name'],
        'platform'    => $r['platform'],
        'active'      => (int) $r['active'],
        'last_ingest' => $r['last_ingest'],
        'in_today'    => 0, 'in_7d' => 0,
        'out_today'   => 0, 'out_7d' => 0,
    ];
}

// 2) Entradas (nuevos por first_seen_at).
foreach ($rows(
    "SELECT store_id,
            SUM(first_seen_at >= CURDATE()) AS today,
            COUNT(*) AS week
       FROM products
      WHERE first_seen_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      GROUP BY store_id"
) as $r) {
    $id = (int) $r['store_id'];
    if (isset($base[$id])) { $base[$id]['in_today'] = (int) $r['today']; $base[$id]['in_7d'] = (int) $r['week']; }
}

// 3) Salidas (desactivados por deactivated_at).
foreach ($rows(
    "SELECT store_id,
            SUM(deactivated_at >= CURDATE()) AS today,
            COUNT(*) AS week
       FROM products
      WHERE deactivated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      GROUP BY store_id"
) as $r) {
    $id = (int) $r['store_id'];
    if (isset($base[$id])) { $base[$id]['out_today'] = (int) $r['today']; $base[$id]['out_7d'] = (int) $r['week']; }
}

// 4) Refrescados HOY por tienda (productos con last_date = hoy). Usa idx_last_date.
$updatedToday = [];
foreach ($rows(
    "SELECT store_id, COUNT(*) AS c
       FROM products
      WHERE is_active = 1 AND last_date = CURDATE()
      GROUP BY store_id"
) as $r) {
    $updatedToday[(int) $r['store_id']] = (int) $r['c'];
}

// 5) Baseline: histórico de conteos (crawl_counts) de los últimos 14 días SIN hoy.
//    Con la mediana detectamos una corrida que trajo bastante MENOS que lo habitual.
$hist = [];
foreach ($rows(
    "SELECT store_id, updated
       FROM crawl_counts
      WHERE day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND day < CURDATE()"
) as $r) {
    $hist[(int) $r['store_id']][] = (int) $r['updated'];
}
$median = static function (array $xs): ?float {
    if (!$xs) { return null; }
    sort($xs); $n = count($xs); $m = intdiv($n, 2);
    return $n % 2 ? (float) $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
};
$DROP_RATIO = 0.70; // < 70% de lo habitual → bandera "low" (corrida posiblemente truncada)

// Frescura en PHP (horas desde la última ingesta) + bandera de atraso.
$now = time();
$out = [];
foreach ($base as $sid => $b) {
    $ts    = $b['last_ingest'] ? strtotime((string) $b['last_ingest']) : null;
    $hours = $ts ? (int) floor(($now - $ts) / 3600) : null;
    $b['hours_since'] = $hours;
    $b['stale']       = $hours === null ? true : ($hours >= $STALE_HOURS);
    $b['warn']        = $hours !== null && $hours >= $WARN_HOURS && $hours < $STALE_HOURS;

    // Conteo de refrescados hoy + baseline (mediana de >=4 días previos).
    $b['updated_today'] = $updatedToday[$sid] ?? 0;
    $med = (isset($hist[$sid]) && count($hist[$sid]) >= 4) ? $median($hist[$sid]) : null;
    $b['baseline'] = $med !== null ? (int) round($med) : null;
    // "low": refrescó bastante menos que lo habitual (y no es una tienda chica/vacía).
    $b['low'] = $med !== null && $med >= 20 && $b['updated_today'] < $DROP_RATIO * $med;

    $out[] = $b;
}

// Orden: de MÁS desactualizada a menos (las "nunca" primero, luego por horas desc).
usort($out, static function ($a, $b) {
    $ha = $a['hours_since'] === null ? PHP_INT_MAX : $a['hours_since'];
    $hb = $b['hours_since'] === null ? PHP_INT_MAX : $b['hours_since'];
    return $hb <=> $ha;
});

out(200, [
    'ok'          => true,
    'stale_hours' => $STALE_HOURS,
    'warn_hours'  => $WARN_HOURS,
    'drop_ratio'  => $DROP_RATIO,
    'generated'   => date('Y-m-d H:i'),
    'stores'      => $out,
]);

} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
