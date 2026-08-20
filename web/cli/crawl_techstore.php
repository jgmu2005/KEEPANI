<?php
declare(strict_types=1);

/**
 * CRAWL de Tech Store Nicaragua — para GitHub Actions.
 *
 * Sitio estático (Netlify, SPA de una sola página). El catálogo está EMBEBIDO en
 * app.js como un array JSON:  const PRODUCTOS_BD = [ {nombre, categoria, ...}, ... ]
 * Bajamos app.js, extraemos el array (balanceando corchetes, respetando strings),
 * lo parseamos y lo mandamos al ingest.
 *
 * No hay SKU ni URL por producto (todo vive en el index), así que:
 *   - sku  = slug del nombre.
 *   - url  = home + #slug (sin ficha propia; el widget no aplica a esta tienda).
 *   - stock= asumido disponible (no hay campo).
 *
 * Uso:  php web/cli/crawl_techstore.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\NormalizedProduct;

const BASE = 'https://techstore-nicaragua.com';
const SLUG = 'techstore';
const CURRENCY = 'NIO';
const TAX_INCLUDED = true;
const TAX_RATE = 0.15;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-') ?: 'producto';
}

/** Extrae el array JSON que sigue a un identificador (ej. PRODUCTOS_BD), balanceando []. */
function extractArray(string $js, string $marker): ?string
{
    $pos = strpos($js, $marker);
    if ($pos === false) { return null; }
    $start = strpos($js, '[', $pos);
    if ($start === false) { return null; }
    $depth = 0; $inStr = false; $esc = false; $n = strlen($js);
    for ($i = $start; $i < $n; $i++) {
        $c = $js[$i];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"') { $inStr = false; }
            continue;
        }
        if ($c === '"') { $inStr = true; }
        elseif ($c === '[') { $depth++; }
        elseif ($c === ']') { $depth--; if ($depth === 0) { return substr($js, $start, $i - $start + 1); } }
    }
    return null;
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

line('=== techstore ===');

$ch = curl_init(BASE . '/app.js');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => UA,
]);
$js = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($js === false || $code < 200 || $code >= 300) {
    fail("No se pudo bajar app.js (code $code).");
}

$block = extractArray((string) $js, 'PRODUCTOS_BD');
if ($block === null) {
    fail('No se encontró el array PRODUCTOS_BD en app.js (¿cambió la estructura?).');
}
$products = json_decode($block, true);
if (!is_array($products)) {
    fail('El array PRODUCTOS_BD no parseó como JSON.');
}
line('  catálogo: ' . count($products) . ' productos');

$http = new Http();
$batch = []; $sent = 0; $seen = []; $skip = 0;

foreach ($products as $p) {
    $name = trim((string) ($p['nombre'] ?? ''));
    $price = isset($p['precio']) ? (float) $p['precio'] : null;
    if ($name === '' || $price === null || $price <= 0) { $skip++; continue; }

    $sku = slugify($name);
    if (isset($seen[$sku])) { continue; }
    $seen[$sku] = true;

    $cat   = trim((string) ($p['categoria'] ?? ''));
    $title = $cat !== '' ? ($name . ' · ' . $cat) : $name;
    $img   = !empty($p['imagen']) ? BASE . '/' . ltrim((string) $p['imagen'], '/') : null;

    $rec = new NormalizedProduct(
        storeSlug:   SLUG,
        sku:         $sku,
        url:         BASE . '/#' . $sku,
        title:       $title,
        brand:       null,
        imageUrl:    $img,
        priceNative: $price,
        currency:    CURRENCY,
        inStock:     true,
        taxIncluded: TAX_INCLUDED,
        taxRate:     TAX_RATE,
    );
    $batch[] = $rec->toArray();
}

if ($batch) {
    $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
    if ($res['status'] !== 200) {
        fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']);
    }
    $sent = count($batch);
}

line("  ✔ techstore: $sent productos enviados" . ($skip ? " · $skip omitidos" : ''));
line("TOTAL enviado: $sent productos");
