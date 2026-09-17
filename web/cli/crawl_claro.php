<?php
declare(strict_types=1);

/**
 * CRAWL de Claro Nicaragua (HCL/wcaas) — GitHub Actions.
 * Solo la categoría pedida: prepago/celulares.
 *
 * La API pública devuelve el feed completo de la categoría en un GET:
 *   https://tiendaenlinea.claro.com.ni/api/wcaas/mongoApi/claroni/productsByCategory/{cat}?lang=es_ni&currency=usd
 *
 * Uso:  php web/cli/crawl_claro.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\ClaroMapper;

const BASE     = 'https://www.claro.com.ni';
const API_BASE = 'https://tiendaenlinea.claro.com.ni/api/wcaas/mongoApi/claroni/productsByCategory/';
const UA       = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const CATS     = ['prepago/celulares'];
const TAX_INCLUDED = true;  // "precio de contado" ya con IVA
const TAX_RATE     = 0.15;
const BATCH    = 25;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }

$http = new Http(UA);
$grand = 0;
$st = ['sent' => 0, 'lost' => 0, 'consec' => 0]; // acumulador tolerante de ingesta

$flush = function (array &$batch) use ($http, $ingestUrl, $ingestKey, &$st): void {
    $r = oap_ingest_batch($http, $ingestUrl, $ingestKey, $batch, $st);
    if ($r === 'auth') { fail('Ingesta rechazada (HTTP 401/403): revisá el secret OJO_INGEST_KEY.'); }
    if ($r === 'down') { fail('Ingesta caída: 8 lotes seguidos fallaron. Reintentá el run.'); }
    $batch = [];
};

foreach (CATS as $cat) {
    line("=== $cat ===");
    $refer = BASE . '/personas/tiendaenlinea/categories/' . $cat;
    $url   = API_BASE . $cat . '?lang=es_ni&currency=usd';

    $data = $http->getJson($url, ['Referer: ' . $refer]);
    if (!is_array($data)) { fail("No se pudo leer la API de $cat"); }
    line('  productos en la API: ' . count($data));

    $batch = []; $before = $st['sent']; $skipped = 0;
    foreach ($data as $p) {
        if (!is_array($p)) { continue; }
        $rec = ClaroMapper::map($p, 'claro', BASE, 'NIO', TAX_INCLUDED, TAX_RATE);
        if ($rec === null) { $skipped++; continue; }
        $batch[] = $rec->toArray();

        if (count($batch) >= BATCH) { $flush($batch); }
    }
    $flush($batch);

    $catSent = $st['sent'] - $before;
    line("  ✔ $cat: $catSent productos" . ($skipped ? " · $skipped sin precio" : ''));
    $grand += $catSent;
}

if ($grand === 0 && $st['lost'] > 0) { fail('No se ingestó ningún producto (el ingest no respondió 200).'); }
line("TOTAL enviado: $grand productos" . ($st['lost'] ? " · {$st['lost']} perdidos en ingesta (se recuperan el próximo run)" : ''));
