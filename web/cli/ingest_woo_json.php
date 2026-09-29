<?php
declare(strict_types=1);

/**
 * Ingesta un WooCommerce Store API YA descargado a un archivo JSON crudo
 * (array de productos tal cual los devuelve /wp-json/wc/store/v1/products).
 *
 * Lo usa el crawler de tiendas detrás de Cloudflare: un navegador headless
 * (Playwright, .github/scripts/fetch_woo_cf.mjs) pasa el challenge y vuelca el
 * JSON crudo; acá lo mapeamos con WooMapper (la MISMA lógica que crawl_woocommerce,
 * sin duplicar) y lo enviamos al ingest con el helper tolerante.
 *
 * Uso:  php web/cli/ingest_woo_json.php <slug> <archivo.json>
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 *       WOO_CURRENCY (def NIO), WOO_TAX_INCLUDED (def 1), WOO_TAX_RATE (def 0.15)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\WooMapper;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$slug = trim((string) ($argv[1] ?? ''));
$file = (string) ($argv[2] ?? '');
if ($slug === '' || $file === '') { fail('Uso: php ingest_woo_json.php <slug> <archivo.json>'); }
if (!is_file($file)) { fail("No existe el archivo: $file"); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.'); }

$currency    = getenv('WOO_CURRENCY') ?: 'NIO';
$taxIncluded = getenv('WOO_TAX_INCLUDED') === false ? true : (getenv('WOO_TAX_INCLUDED') !== '0');
$taxRate     = getenv('WOO_TAX_RATE') !== false ? (float) getenv('WOO_TAX_RATE') : 0.15;

$raw = file_get_contents($file);
$data = json_decode((string) $raw, true);
if (!is_array($data) || !array_is_list($data)) {
    fail('El JSON no es una lista de productos (¿el fetch del navegador falló?).');
}
line("=== $slug (desde JSON: " . count($data) . " productos crudos) ===");

$http = new Http();
$seen = []; $batch = []; $skipped = 0;
$st = ['sent' => 0, 'lost' => 0, 'consec' => 0];

$flush = function () use (&$batch, &$st, $http, $ingestUrl, $ingestKey): void {
    $r = oap_ingest_batch($http, $ingestUrl, $ingestKey, $batch, $st);
    if ($r === 'auth') { fail('Ingesta rechazada (HTTP 401/403): revisá el secret OJO_INGEST_KEY.'); }
    if ($r === 'down') { fail('Ingesta caída: 8 lotes seguidos fallaron. Reintentá el run.'); }
    $batch = [];
};

foreach ($data as $p) {
    if (!is_array($p)) { continue; }
    $handle = WooMapper::handle($p);
    if ($handle === '' || isset($seen[$handle])) { continue; }
    $seen[$handle] = true;
    $rec = WooMapper::map($p, $slug, $currency, $taxIncluded, $taxRate);
    if ($rec === null) { $skipped++; continue; }
    $batch[] = $rec->toArray();
    if (count($batch) >= 100) { $flush(); }
}
$flush();

if ($seen && $st['sent'] === 0) { fail('No se ingestó ningún producto (el ingest no respondió 200).'); }
line("  ✔ $slug: {$st['sent']} productos únicos" . ($skipped ? " · $skipped sin precio/slug" : '')
    . ($st['lost'] ? " · {$st['lost']} perdidos (se recuperan el próximo run)" : ''));
line("TOTAL enviado: {$st['sent']} productos");
