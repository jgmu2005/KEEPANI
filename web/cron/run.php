<?php
declare(strict_types=1);

/**
 * RUNNER DIARIO — lo dispara cron-job.org con una petición HTTP.
 *
 *   GET/POST https://agrotecnicaragua.com/ojoalprecio/cron/run.php
 *   Header:  X-Api-Key: <ingest_api_key de config.php>
 *   Query:   ?limit=N   (opcional; cuántos productos refrescar por corrida)
 *
 * Recorre los productos activos, refresca precio/stock con el adaptador de su
 * tienda (cURL, sin navegador) y persiste vía IngestService — todo en el mismo
 * servidor, sin dar la vuelta por HTTP.
 *
 * Procesa primero los MÁS DESACTUALIZADOS, así si hay muchos productos se pueden
 * cubrir en varias corridas (batching) sin exceder el tiempo de ejecución.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\AdapterFactory;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0); // best-effort; en shared hosting puede estar limitado

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Auth (mismo secreto que la ingesta) ---
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || $expected === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA'
    || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$limit = (int) ($_GET['limit'] ?? 200);
$limit = max(1, min($limit, 1000));

try {
    $db = Db::conn();

    // Mantenimiento: bajar a 'free' las suscripciones vencidas (Ko-fi no siempre avisa).
    $db->exec("UPDATE users SET tier = 'free'
                WHERE tier = 'subscriber' AND subscription_until IS NOT NULL AND subscription_until < NOW()");

    // Tiendas indexadas por id.
    $stores = [];
    foreach ($db->query('SELECT * FROM stores') as $s) {
        $stores[(int) $s['id']] = $s;
    }

    // Productos activos, los más desactualizados primero (NULL = nunca capturado).
    $sql = 'SELECT p.id, p.store_id, p.external_sku
              FROM products p
              LEFT JOIN (
                    SELECT product_id, MAX(captured_at) AS last_at
                      FROM price_history GROUP BY product_id
              ) ph ON ph.product_id = p.id
             WHERE p.is_active = 1
             ORDER BY (ph.last_at IS NULL) DESC, ph.last_at ASC
             LIMIT ' . $limit;
    $products = $db->query($sql)->fetchAll();

    $http    = new Http();
    $records = [];
    $failed  = [];

    foreach ($products as $p) {
        $store = $stores[(int) $p['store_id']] ?? null;
        if (!$store) {
            continue;
        }
        try {
            $adapter = AdapterFactory::fromStore($store, $http);
            $rec = $adapter->fetchBySku((string) $p['external_sku']);
            if ($rec === null) {
                $failed[] = ['store' => $store['slug'], 'sku' => $p['external_sku']];
                continue;
            }
            $records[] = $rec->toArray();
        } catch (\Throwable $e) {
            $failed[] = ['store' => $store['slug'] ?? '?', 'sku' => $p['external_sku'], 'error' => $e->getMessage()];
        }
    }

    $result = (new IngestService($db))->ingest($records);
    $result['scanned']      = count($products);
    $result['fetch_failed'] = count($failed);
    $result['failures']     = $failed;

    out(200, $result);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
