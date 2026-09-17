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

// Sharding opcional (para tiendas grandes que no entran en el límite de tiempo):
//   crawl_sitemap.php tropigas 1 3   → procesa el tercio 1 de 3 del sitemap.
// Sólo aplica cuando se apunta a UNA tienda.
$shard = max(1, (int) ($argv[2] ?? 1));
$of    = max(1, (int) ($argv[3] ?? 1));

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
    // Si es un ÍNDICE de sitemaps (los <loc> terminan en .xml), bajamos cada
    // sub-sitemap y usamos sus URLs. Así resiste que la tienda parta el sitemap en
    // índice + hijos (El Gallo lo hizo: -urls.xml / -images.xml → antes daba 0).
    $docs = [$xml];
    preg_match_all('~<loc>\s*(https?://[^<\s]+?)\s*</loc>~', $xml, $mi);
    foreach ($mi[1] as $loc) {
        if (preg_match('~\.xml(?:\.gz)?$~i', $loc)) {
            $sub = $http->get($loc);
            if ($sub !== null) { $docs[] = $sub; }
        }
    }
    $urls = [];
    foreach ($docs as $doc) {
        preg_match_all('~<loc>\s*(https?://[^<\s]+?)\s*</loc>~', $doc, $m);
        foreach ($m[1] as $loc) {
            if (preg_match('~-(\d+)(?:/p)?/?$~', $loc, $mm)) {
                $urls[$loc] = $mm[1]; // solo URLs de producto (-id o -id/p); dedup por url
            }
        }
    }
    line('  productos en el sitemap: ' . count($urls) . (count($docs) > 1 ? ' (índice: ' . (count($docs) - 1) . ' sub-sitemaps)' : ''));

    // Si es una sola tienda y se pidió shard, procesamos sólo su porción.
    if ($of > 1 && $which !== 'all') {
        $size = (int) ceil(count($urls) / $of);
        $urls = array_slice($urls, ($shard - 1) * $size, $size, true);
        line("  shard $shard/$of → " . count($urls) . ' en este job');
    }

    $adapter = new OgMetaAdapter($http, $slug, $cfg['base_url'], '', $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate']);
    $batch = []; $fails = 0; $i = 0; $st = ['sent' => 0, 'lost' => 0, 'consec' => 0];

    // Envía el lote actual con el helper tolerante (reintenta 3× con backoff; un
    // lote perdido se recupera el próximo run porque la ingesta es idempotente por
    // día). Solo aborta si el secret está mal o si caen 8 lotes seguidos.
    $flush = function () use (&$batch, &$st, $http, $ingestUrl, $ingestKey) {
        $r = oap_ingest_batch($http, $ingestUrl, $ingestKey, $batch, $st);
        if ($r === 'auth') { fail('Ingesta rechazada (HTTP 401/403): revisá el secret OJO_INGEST_KEY.'); }
        if ($r === 'down') { fail('Ingesta caída: 8 lotes seguidos fallaron. Reintentá el run.'); }
        $batch = [];
    };

    foreach ($urls as $url => $sku) {
        $i++;
        $rec = $adapter->fetchByUrl($url, (string) $sku);
        if ($rec === null) { $fails++; }
        else { $batch[] = $rec->toArray(); }

        if (count($batch) >= BATCH) { $flush(); }
        if ($i % 50 === 0) { line("  ...$i/" . count($urls) . " · {$st['sent']} enviados · $fails sin OG · {$st['lost']} perdidos"); }
        usleep(250000);
    }
    $flush();

    line("  ✔ $slug: {$st['sent']} productos" . ($fails ? " · $fails sin OG" : '') . ($st['lost'] ? " · {$st['lost']} perdidos en ingesta (se recuperan el próximo run)" : ''));
    $grand += $st['sent'];
}

line("TOTAL enviado: $grand productos");
