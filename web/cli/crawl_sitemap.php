<?php
declare(strict_types=1);

/**
 * CRAWL por SITEMAP — tiendas Magento/OG (El Gallo más Gallo).
 *
 * Lee el sitemap, saca las URLs de producto (terminan en -{id}), baja el OG de
 * cada una (precio/stock/título) y envía por lotes a /api/ingest.
 *
 * Uso:  php web/cli/crawl_sitemap.php [gallo|all]
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\OgMetaAdapter;

const STORES = [
    'gallo' => [
        'base_url' => 'https://www.elgallomasgallo.com.ni',
        'sitemap'  => 'https://www.elgallomasgallo.com.ni/media/sitemap_tienda_el_gallo_ni.xml',
        'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15,
    ],
    // Unicomer (Magento, /nicaragua/…-{id}/p) — sin product:availability → OG asume en stock.
    'lacuracao' => [
        'base_url' => 'https://www.lacuracaonline.com',
        'sitemap'  => 'https://www.lacuracaonline.com/media/sitemap/sitemap_lco_ni_products.xml',
        'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15,
    ],
    'radioshack' => [
        'base_url' => 'https://www.radioshackla.com',
        'sitemap'  => 'https://www.radioshackla.com/media/sitemap/sitemap_rso_ni_products.xml',
        'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15,
    ],
    'tropigas' => [
        'base_url' => 'https://www.almacenestropigas.com',
        'sitemap'  => 'https://www.almacenestropigas.com/media/sitemap/sitemap_tg_nic.xml',
        'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15,
    ],
];
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const BATCH = 25;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.');
}

$which   = $argv[1] ?? 'all';
$targets = $which === 'all' ? array_keys(STORES) : [$which];

$http = new Http(UA);
$grand = 0;

foreach ($targets as $slug) {
    if (!isset(STORES[$slug])) {
        fail("Tienda desconocida: $slug");
    }
    $cfg = STORES[$slug];
    line("=== $slug ===");

    $xml = $http->get($cfg['sitemap']);
    if ($xml === null) {
        fail("No se pudo bajar el sitemap de $slug");
    }
    preg_match_all('~<loc>\s*(https?://[^<\s]+?)\s*</loc>~', $xml, $m);
    $urls = [];
    foreach ($m[1] as $loc) {
        if (preg_match('~-(\d+)(?:/p)?/?$~', $loc, $mm)) {
            $urls[$loc] = $mm[1]; // solo URLs de producto (-id o -id/p); dedup por url
        }
    }
    line('  productos en el sitemap: ' . count($urls));

    $adapter = new OgMetaAdapter($http, $slug, $cfg['base_url'], '', $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate']);
    $batch = []; $sent = 0; $fails = 0; $i = 0; $lost = 0; $consec = 0;

    // Envía el lote actual. La ingesta es idempotente por día, así que un lote
    // perdido por un blip de red se recupera en el próximo run (no aborta todo).
    $flush = function () use (&$batch, &$sent, &$lost, &$consec, $http, $ingestUrl, $ingestKey) {
        if (!$batch) { return; }
        $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
        if ($res['status'] === 200) {
            $sent += count($batch); $consec = 0;
        } elseif ($res['status'] === 401 || $res['status'] === 403) {
            fail("Ingesta rechazada (HTTP {$res['status']}): revisá el secret OJO_INGEST_KEY.");
        } else {
            $lost += count($batch); $consec++;
            $why = $res['error'] !== '' ? $res['error'] : "HTTP {$res['status']}";
            line("  ⚠ ingesta falló ($why) — lote de " . count($batch) . " descartado, sigo");
            if ($consec >= 5) { fail("Ingesta caída: 5 lotes seguidos fallaron. Aborto (reintentá el run luego)."); }
        }
        $batch = [];
    };

    foreach ($urls as $url => $sku) {
        $i++;
        $rec = $adapter->fetchByUrl($url, (string) $sku);
        if ($rec === null) { $fails++; }
        else { $batch[] = $rec->toArray(); }

        if (count($batch) >= BATCH) { $flush(); }
        if ($i % 50 === 0) { line("  ...$i/" . count($urls) . " · $sent enviados · $fails sin OG · $lost perdidos"); }
        usleep(250000);
    }
    $flush();

    line("  ✔ $slug: $sent productos" . ($fails ? " · $fails sin OG" : '') . ($lost ? " · $lost perdidos en ingesta" : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
