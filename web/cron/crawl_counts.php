<?php
declare(strict_types=1);

/**
 * CRON — snapshot del conteo diario de productos REFRESCADOS por tienda.
 *
 *   GET /cron/crawl_counts.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Cuenta, por tienda, cuántos productos activos quedaron con last_date = HOY (= se
 * les actualizó el precio hoy) y lo guarda en crawl_counts (upsert: se queda el
 * valor más alto del día). Correr VARIAS veces al día (cron-job.org, p.ej. cada 4h):
 * como los crawls van corriendo a distintas horas (Actions de madrugada, local 9 AM),
 * el conteo va creciendo y el último run del día deja el total real.
 *
 * El panel de salud y health_alert.php comparan este conteo contra la mediana de los
 * días previos para detectar corridas que trajeron de menos (bajada silenciosa).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

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

    // Productos refrescados HOY por tienda (last_date = CURDATE()). Usa idx_last_date.
    $counts = $db->query(
        'SELECT store_id, COUNT(*) AS c
           FROM products
          WHERE is_active = 1 AND last_date = CURDATE()
          GROUP BY store_id'
    )->fetchAll();

    $upsert = $db->prepare(
        'INSERT INTO crawl_counts (day, store_id, updated, captured_at)
         VALUES (CURDATE(), :sid, :c, NOW())
         ON DUPLICATE KEY UPDATE
            updated     = GREATEST(updated, VALUES(updated)),
            captured_at = NOW()'
    );

    $saved = [];
    foreach ($counts as $r) {
        $sid = (int) $r['store_id'];
        $c   = (int) $r['c'];
        $upsert->execute([':sid' => $sid, ':c' => $c]);
        $saved[$sid] = $c;
    }

    out(200, ['ok' => true, 'day' => date('Y-m-d'), 'stores' => count($saved), 'counts' => $saved]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
