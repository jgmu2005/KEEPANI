<?php
declare(strict_types=1);

/**
 * CRON — crawl del MARKETPLACE (tiendas Treinta). Aislado del tracker.
 *
 *   GET/POST /cron/crawl_marketplace.php   Header: X-Api-Key: <ingest_api_key>
 *   Query:   ?store=slug   (opcional; una sola tienda)   ?debug=1 (no escribe)
 *
 * Cada tienda = 1 fetch (catálogo completo embebido en el HTML). Actualiza
 * mk_products + inserta el punto de precio del día en mk_price_history.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Marketplace\TreintaParser;
use OjoAlPrecio\Web\Marketplace\MarketplaceRepo;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$repo = new MarketplaceRepo($db);
$http = new Http('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36', 25, 1);
$debug = !empty($_GET['debug']);
$only  = trim((string) ($_GET['store'] ?? ''));

$stores = $repo->activeStores();
$report = [];

foreach ($stores as $s) {
    if ($only !== '' && $s['slug'] !== $only) { continue; }
    $html = $http->get((string) $s['url']);
    if ($html === null) {
        $report[] = ['store' => $s['slug'], 'ok' => false, 'error' => 'fetch falló'];
        continue;
    }
    $parsed = TreintaParser::parse($html);
    $items  = $parsed['items'];

    if ($debug) {
        $report[] = ['store' => $s['slug'], 'found' => count($items),
                     'store_name' => $parsed['store_name'],
                     'sample' => array_slice(array_map(static fn($i) => $i['name'] . ' = ' . ($i['price'] ?? '—'), $items), 0, 5)];
        continue;
    }

    $seen = [];
    foreach ($items as $it) {
        if ($it['price'] === null) { continue; }
        $repo->ingestProduct((int) $s['id'], $it);
        $seen[] = $it['ext_id'];
    }
    $deactivated = $repo->deactivateMissing((int) $s['id'], $seen);
    $repo->touchStore((int) $s['id'], $parsed['store_name']);

    $report[] = ['store' => $s['slug'], 'ok' => true, 'ingested' => count($seen), 'deactivated' => $deactivated];
}

out(200, ['ok' => true, 'stores' => count($report), 'result' => $report]);
