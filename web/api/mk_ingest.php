<?php
declare(strict_types=1);

/**
 * /api/mk_ingest.php — ingesta REMOTA del marketplace (para GitHub Actions).
 * Auth: X-Api-Key (ingest_api_key).
 *
 *   GET  → lista tiendas activas [{slug, url, name}]  (para que el crawler sepa qué bajar)
 *   POST {store: slug, store_name?, products: [{ext_id,name,price,image_url,in_stock,currency}]}
 *        → upsert de productos + punto de precio del día + desactiva los que faltan.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Marketplace\MarketplaceRepo;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$repo = new MarketplaceRepo($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stores = array_map(
        static fn(array $s): array => ['slug' => $s['slug'], 'url' => $s['url'], 'name' => $s['name']],
        $repo->activeStores()
    );
    out(200, ['ok' => true, 'stores' => $stores]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || empty($in['store']) || !isset($in['products']) || !is_array($in['products'])) {
    out(400, ['ok' => false, 'error' => 'Payload inválido (se espera {store, products[]})']);
}

$store = $repo->findStore((string) $in['store']);
if (!$store) {
    out(404, ['ok' => false, 'error' => 'Tienda no registrada: ' . $in['store']]);
}
$sid = (int) $store['id'];

$seen = 0; $seenIds = [];
foreach ($in['products'] as $p) {
    $ext   = isset($p['ext_id']) ? (string) $p['ext_id'] : '';
    $name  = isset($p['name']) ? trim((string) $p['name']) : '';
    $price = isset($p['price']) && $p['price'] !== '' && $p['price'] !== null ? (float) $p['price'] : null;
    if ($ext === '' || $name === '' || $price === null || $price <= 0) { continue; }
    $repo->ingestProduct($sid, [
        'ext_id'    => substr($ext, 0, 64),
        'name'      => $name,
        'price'     => $price,
        'image_url' => isset($p['image_url']) ? (string) $p['image_url'] : '',
        'in_stock'  => !empty($p['in_stock']) ? 1 : 0,
        'currency'  => isset($p['currency']) ? (string) $p['currency'] : 'NIO',
    ]);
    $seenIds[] = substr($ext, 0, 64);
    $seen++;
}

$deactivated = $repo->deactivateMissing($sid, $seenIds);
$repo->touchStore($sid, isset($in['store_name']) ? (string) $in['store_name'] : null);

out(200, ['ok' => true, 'store' => $in['store'], 'ingested' => $seen, 'deactivated' => $deactivated]);
