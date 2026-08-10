<?php
declare(strict_types=1);

/**
 * CRAWL COMPLETO de Copasa (ASP.NET, electrodomésticos) — para GitHub Actions.
 *
 * Copasa no tiene API ni sitemap de productos, pero sus listados de categoría son
 * server-rendered y paginados. Estrategia:
 *   1) Enumerar SKUs paginando /Catalog/Category/Catalog/Products/ALLBRANDS/0/0/0/{p}
 *   2) Por cada SKU, bajar /Product/Detail/{sku} y leer del OG el precio, stock y
 *      moneda; la marca sale del breadcrumb. El NOMBRE en Copasa es client-side
 *      (vacío en el HTML), así que usamos "marca + modelo(SKU)".
 *   3) POST por lotes a /api/ingest.
 *
 * Uso:  php web/cli/crawl_copasa.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;

const BASE = 'https://www.copasa.com.ni';
const SLUG = 'copasa';
const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const BATCH = 25;
const MAX_PAGES = 400; // salvaguarda

// Marcas conocidas (para sacar la marca del breadcrumb). Segmento EXACTO.
const BRANDS = [
    'SAMSUNG','LG','WHIRLPOOL','MABE','FRIGIDAIRE','FRIGIDAIREL','TCL','SONY','OSTER','ELECTROLUX',
    'GE','ATLAS','PANASONIC','HISENSE','INDURAMA','SANKEY','PREMIUM','MIDEA','HAIER','KALLEY',
    'BOSCH','DAEWOO','XIAOMI','MOTOROLA','EPSON','HP','ACER','IMACO','HAMILTON','KITCHENAID',
    'ROYAL','ECASA','SMC','SUPERIOR','CENTRALES','DACE','BLACKDECKER','MASTERTECH','TEKA','MADESA',
];

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

function getHtml(string $url, int $retries = 4): ?string
{
    $code = 0;
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Referer: ' . BASE . '/'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) { return (string) $body; }
        if ($code === 000 || $code === 429 || $code >= 500 || $body === false) { usleep(1200000 * ($a + 1)); continue; }
        break;
    }
    fwrite(STDERR, "  [fetch] falló (code $code): $url\n");
    return null;
}

function metaContent(string $html, string $key): ?string
{
    if (preg_match('#<meta[^>]*(?:property|name)="' . preg_quote($key, '#') . '"[^>]*content="([^"]*)"#i', $html, $m)) {
        return trim($m[1]);
    }
    if (preg_match('#<meta[^>]*content="([^"]*)"[^>]*(?:property|name)="' . preg_quote($key, '#') . '"#i', $html, $m)) {
        return trim($m[1]);
    }
    return null;
}

function brandOf(string $html): ?string
{
    if (preg_match_all('#/Catalog/Category/Catalog/([A-Za-z0-9]+)/ALLBRANDS/#', $html, $m)) {
        foreach ($m[1] as $seg) {
            if (in_array(strtoupper($seg), BRANDS, true)) {
                return ucfirst(strtolower($seg));
            }
        }
    }
    return null;
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }

$http = new Http(UA);

// --- 1) Enumerar SKUs ---
// Copasa publica en la web SOLO lo que tiene con buen inventario (catálogo chico).
// Barremos "Products" (destacados) + cada categoría de marca conocida, y dedup.
$cats = array_merge(['Products'], BRANDS);
$skus = [];
foreach ($cats as $cat) {
    $before = count($skus);
    for ($p = 1; $p <= MAX_PAGES; $p++) {
        $html = getHtml(BASE . '/Catalog/Category/Catalog/' . rawurlencode($cat) . '/ALLBRANDS/0/0/0/' . $p);
        if ($html === null) { break; }
        preg_match_all('#/Product/Detail/([A-Za-z0-9_.\-]+)#', $html, $m);
        $new = 0;
        foreach ($m[1] as $sku) { if (!isset($skus[$sku])) { $skus[$sku] = true; $new++; } }
        if ($new === 0) { break; } // página sin productos nuevos → fin de esa categoría
        usleep(300000);
    }
    $added = count($skus) - $before;
    if ($added > 0) { line("  categoría $cat: +$added (total " . count($skus) . ')'); }
}
$skus = array_keys($skus);
line('SKUs enumerados: ' . count($skus));

// --- 2) Ficha por SKU → precio/stock/marca ---
$batch = []; $sent = 0; $noPrice = 0; $fails = 0;
$flush = function () use (&$batch, &$sent, $http, $ingestUrl, $ingestKey) {
    if (!$batch) { return; }
    $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
    if ($res['status'] === 200) { $sent += count($batch); }
    elseif ($res['status'] === 401 || $res['status'] === 403) { fail("Ingesta rechazada (HTTP {$res['status']})."); }
    else { fwrite(STDERR, "  ⚠ ingesta HTTP {$res['status']} — lote descartado\n"); }
    $batch = [];
};

foreach ($skus as $i => $sku) {
    $url  = BASE . '/Product/Detail/' . rawurlencode($sku);
    $html = getHtml($url);
    if ($html === null) { $fails++; continue; }

    $price = metaContent($html, 'product:price:amount');
    if ($price === null || (float) $price <= 0) { $noPrice++; usleep(300000); continue; }
    $avail = strtolower((string) metaContent($html, 'product:availability'));
    $cur   = metaContent($html, 'product:price:currency') ?: 'NIO';
    $brand = brandOf($html);
    $img   = null;
    if (preg_match('#https://s3\.amazonaws\.com/online\.storage/COPASA/Products/[a-f0-9\-]+\.(?:png|jpg|jpeg)#i', $html, $mi)) { $img = $mi[0]; }

    $batch[] = [
        'store'       => SLUG,
        'sku'         => $sku,
        'url'         => BASE . '/Product/Detail/' . $sku,
        'title'       => trim(($brand ? $brand . ' ' : '') . $sku),
        'brand'       => $brand,
        'image_url'   => $img,
        'price_final' => (float) $price,
        'currency'    => $cur,
        'in_stock'    => str_contains($avail, 'in stock') || str_contains($avail, 'instock') ? 1 : 0,
    ];
    if (count($batch) >= BATCH) { $flush(); }
    if (($i + 1) % 50 === 0) { line('  ...' . ($i + 1) . '/' . count($skus) . " · $sent enviados · $noPrice sin precio · $fails fallos"); }
    usleep(300000);
}
$flush();

line("TOTAL: $sent productos · $noPrice sin precio · $fails fallos de fetch");
