<?php
declare(strict_types=1);

/**
 * SEED REMOTO — crawler VTEX pensado para correr en GitHub Actions (o local).
 *
 * A diferencia de web/cron/seed.php (que corre en FatCow y escribe directo a la
 * BD), este NO toca la base: recorre el catálogo VTEX en el runner y envía cada
 * página por HTTP a /api/ingest.php. Así el trabajo pesado sale de FatCow.
 *
 * Uso:
 *   php web/cli/seed_remote.php [sinsa|siman|all]
 *
 * Variables de entorno (las inyecta GitHub Actions desde los "secrets"):
 *   OJO_INGEST_URL   p.ej. https://agrotecnicaragua.com/ojoalprecio/api/ingest.php
 *   OJO_INGEST_KEY   la misma ingest_api_key de config.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\VtexCatalogCrawler;

// Config de las tiendas VTEX (no necesita BD; el runner no la tiene).
const STORES = [
    'sinsa' => ['base_url' => 'https://www.sinsa.com.ni', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
    'siman' => ['base_url' => 'https://ni.siman.com',      'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
];

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

$which = $argv[1] ?? 'all';
$targets = $which === 'all' ? array_keys(STORES) : [$which];

$http = new Http();
$grandTotal = 0;

foreach ($targets as $slug) {
    if (!isset(STORES[$slug])) {
        fail("Tienda desconocida: $slug (usá sinsa, siman o all)");
    }
    $store = ['slug' => $slug] + STORES[$slug];
    $crawler = VtexCatalogCrawler::fromStore($store, $http);

    line("=== $slug ===");
    $from = 0;
    $sent = 0;

    while ($from <= VtexCatalogCrawler::MAX_OFFSET) {
        $recs = $crawler->page($from);
        if (!$recs) {
            break; // no hay más productos
        }

        $res = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
        if ($res['status'] !== 200) {
            fail("Ingesta falló en offset $from (HTTP {$res['status']}): {$res['body']}");
        }

        $sent += count($recs);
        line(sprintf('  offset %5d  → %2d productos enviados (acum: %d)', $from, count($recs), $sent));

        $from += VtexCatalogCrawler::PAGE_SIZE;
        usleep(400000); // 0.4s entre páginas: cortés con la tienda
    }

    line("  ✔ $slug: $sent productos");
    $grandTotal += $sent;
}

line("TOTAL enviado: $grandTotal");
