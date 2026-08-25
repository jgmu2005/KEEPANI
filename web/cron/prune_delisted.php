<?php
declare(strict_types=1);

/**
 * CRON — marca inactivos los productos DESCONTINUADOS (delisted).
 *
 * Cuando una tienda saca un producto de su catálogo, el crawler deja de verlo,
 * pero nada lo marcaba inactivo: quedaba con precio viejo y link muerto. Este job
 * marca is_active=0 los productos cuyo last_seen_at quedó MUY atrás respecto a la
 * corrida MÁS FRESCA de su propia tienda.
 *
 * Es seguro ante fallos de crawl: si una tienda entera dejó de crawlearse, su
 * fecha "fresca" también baja, así que el desfase se mantiene chico y no se marca
 * nada de más. Sólo cae lo que quedó atrás cuando el RESTO de la tienda sí se vio.
 *
 *   GET /cron/prune_delisted.php?days=3        Header: X-Api-Key: <ingest_api_key>
 *   GET /cron/prune_delisted.php?days=3&dry=1  (preview: sólo cuenta, no cambia nada)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($key) || !hash_equals($expected, $key)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$days = max(1, min((int) ($_GET['days'] ?? 3), 60));
$dry  = !empty($_GET['dry']);

try {

// Desglose por tienda de lo que se marcaría (o marcó).
$breakdown = $db->prepare(
    "SELECT s.name AS store, COUNT(*) AS n
       FROM products p
       JOIN stores s ON s.id = p.store_id
       JOIN (SELECT store_id, MAX(last_seen_at) AS fresh
               FROM products WHERE is_active = 1 GROUP BY store_id) f
         ON f.store_id = p.store_id
      WHERE p.is_active = 1
        AND p.last_seen_at < (f.fresh - INTERVAL {$days} DAY)
      GROUP BY s.id, s.name
      ORDER BY n DESC"
);
$breakdown->execute();
$rows  = $breakdown->fetchAll();
$total = array_sum(array_map(static fn($r) => (int) $r['n'], $rows));

$marked = 0;
if (!$dry && $total > 0) {
    $upd = $db->prepare(
        "UPDATE products p
           JOIN (SELECT store_id, MAX(last_seen_at) AS fresh
                   FROM products WHERE is_active = 1 GROUP BY store_id) f
             ON f.store_id = p.store_id
            SET p.is_active = 0
          WHERE p.is_active = 1
            AND p.last_seen_at < (f.fresh - INTERVAL {$days} DAY)"
    );
    $upd->execute();
    $marked = $upd->rowCount();
}

out(200, [
    'ok'        => true,
    'dry'       => $dry,
    'days'      => $days,
    'candidate' => $total,      // cuántos quedaron atrás (delisted)
    'marked'    => $marked,     // cuántos se marcaron inactivos (0 en dry)
    'by_store'  => array_map(static fn($r) => ['store' => $r['store'], 'n' => (int) $r['n']], $rows),
]);

} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
