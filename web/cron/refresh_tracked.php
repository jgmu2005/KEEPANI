<?php
declare(strict_types=1);

/**
 * REFRESCO PRIORITARIO DE PRODUCTOS RASTREADOS — diferenciador Premium.
 *
 *   GET/POST /cron/refresh_tracked.php   Header: X-Api-Key: <ingest_api_key>
 *   Query:   ?tiers=subscriber   (o ?tiers=onetime,subscriber)   [obligatorio]
 *            ?limit=N            (tope de productos por corrida; opcional)
 *
 * Refresca precio/stock SOLO de los productos con alertas activas cuyos dueños
 * pertenecen a los niveles indicados, y dispara las notificaciones para esos
 * productos. Así los usuarios de pago reciben avisos más rápido que el refresco
 * diario general (run.php):
 *
 *   - suscripción (subscriber): cron cada 4h  → 6× al día
 *   - un pago     (onetime):    cron 2× al día
 *   - gratis      (free):       cubierto por run.php (1× al día)
 *
 * Reusa el mismo pipeline que run.php (adaptadores + IngestService) y luego
 * Alerts::notify() acotado a los productos refrescados.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Alerts;
use OjoAlPrecio\Web\Mailer;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\AdapterFactory;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Auth (mismo secreto que la ingesta) ---
$cfg      = Db::config();
$sent     = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || $expected === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA'
    || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// --- Niveles a refrescar ---
$ALLOWED = ['free', 'onetime', 'subscriber'];
$raw     = strtolower(trim((string) ($_GET['tiers'] ?? 'subscriber')));
$tiers   = array_values(array_intersect($ALLOWED, array_map('trim', explode(',', $raw))));
if (!$tiers) {
    out(400, ['ok' => false, 'error' => 'tiers inválido', 'allowed' => $ALLOWED]);
}

$limit = (int) ($_GET['limit'] ?? 300);
$limit = max(1, min($limit, 1000));

try {
    $db = Db::conn();

    // Mantenimiento: bajar a 'free' las suscripciones vencidas.
    $db->exec("UPDATE users SET tier = 'free'
                WHERE tier = 'subscriber' AND subscription_until IS NOT NULL AND subscription_until < NOW()");

    // Tiendas indexadas por id.
    $stores = [];
    foreach ($db->query('SELECT * FROM stores') as $s) {
        $stores[(int) $s['id']] = $s;
    }

    // Productos DISTINTOS con alerta activa de un dueño en los niveles pedidos.
    // Los más desactualizados primero (batching entre corridas).
    $ph  = implode(',', array_fill(0, count($tiers), '?'));
    $sql = 'SELECT p.id, p.store_id, p.external_sku, p.url
              FROM products p
              JOIN alerts a ON a.product_id = p.id AND a.is_active = 1
              JOIN users  u ON u.id = a.user_id AND u.tier IN (' . $ph . ')
              LEFT JOIN (
                    SELECT product_id, MAX(captured_at) AS last_at
                      FROM price_history GROUP BY product_id
              ) hh ON hh.product_id = p.id
             WHERE p.is_active = 1
             GROUP BY p.id, p.store_id, p.external_sku, p.url
             ORDER BY (MAX(hh.last_at) IS NULL) DESC, MAX(hh.last_at) ASC
             LIMIT ' . $limit;
    $st = $db->prepare($sql);
    $st->execute($tiers);
    $products = $st->fetchAll();

    $http    = new Http();
    $records = [];
    $failed  = [];
    $ids     = [];

    foreach ($products as $p) {
        $ids[]  = (int) $p['id'];
        $store  = $stores[(int) $p['store_id']] ?? null;
        if (!$store) { continue; }
        try {
            $adapter = AdapterFactory::fromStore($store, $http);
            $rec = $adapter->fetchByUrl((string) $p['url'], (string) $p['external_sku']);
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

    // Notifica SOLO por los productos que acabamos de refrescar.
    $mailer   = Mailer::fromSettings($db);
    $siteName = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
    $base     = Verification::baseUrl();
    $notif    = $ids ? Alerts::notify($db, $mailer, $cfg, $siteName, $base, $ids) : [];

    out(200, [
        'ok'           => true,
        'tiers'        => $tiers,
        'tracked'      => count($products),
        'fetch_failed' => count($failed),
        'ingest'       => $result,
        'alerts'       => $notif,
        'failures'     => $failed,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
