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
        if (preg_match('~-(\d+)/?$~', $loc, $mm)) {
            $urls[$loc] = $mm[1]; // solo URLs de producto (terminan en -id); dedup por url
        }
    }
    line('  productos en el sitemap: ' . count($urls));

    $adapter = new OgMetaAdapter($http, $slug, $cfg['base_url'], '', $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate']);
    $batch = []; $sent = 0; $fails = 0; $i = 0;

    foreach ($urls as $url => $sku) {
        $i++;
        $rec = $adapter->fetchByUrl($url, (string) $sku);
        if ($rec === null) { $fails++; }
        else { $batch[] = $rec->toArray(); }

        if (count($batch) >= BATCH) {
            $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
            if ($res['status'] !== 200) { fail("Ingesta HTTP {$res['status']}: " . $res['body']); }
            $sent += count($batch); $batch = [];
        }
        if ($i % 50 === 0) { line("  ...$i/" . count($urls) . " · $sent enviados · $fails fallos"); }
        usleep(400000);
    }
    if ($batch) {
        $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
        if ($res['status'] !== 200) { fail("Ingesta HTTP {$res['status']}: " . $res['body']); }
        $sent += count($batch);
    }

    line("  ✔ $slug: $sent productos" . ($fails ? " · $fails fallos" : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
