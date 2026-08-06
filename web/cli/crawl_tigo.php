<?php
declare(strict_types=1);

/**
 * CRAWL de Tigo Nicaragua (catálogo prepago) — GitHub Actions.
 *
 * La página server-rendered incluye un JSON-LD ItemList con todos los celulares
 * (name, image, offers.price en NIO). Se parsea directo — sin API que reversear.
 * OJO: el precio del JSON-LD usa '.' como separador de miles ("3.799" = C$3 799).
 *
 * Uso:  php web/cli/crawl_tigo.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\NormalizedProduct;

const URL = 'https://www.tigo.com.ni/catalogo-prepago';
const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-') ?: 'producto';
}

function brandFromName(string $name): ?string
{
    $n = mb_strtolower($name, 'UTF-8');
    $map = [
        'iphone'=>'Apple','apple'=>'Apple','samsung'=>'Samsung','galaxy'=>'Samsung',
        'honor'=>'Honor','huawei'=>'Huawei','xiaomi'=>'Xiaomi','redmi'=>'Xiaomi','poco'=>'Xiaomi',
        'motorola'=>'Motorola','moto '=>'Motorola','tecno'=>'Tecno','infinix'=>'Infinix','itel'=>'itel',
        'nokia'=>'Nokia','oppo'=>'Oppo','realme'=>'Realme','zte'=>'ZTE','alcatel'=>'Alcatel',
    ];
    foreach ($map as $k => $v) {
        if (str_contains($n, $k)) { return $v; }
    }
    return null;
}

/** "3.799" (miles con punto) → 3799.0 ; "850" → 850.0 */
function parsePrice(string $s): ?float
{
    $s = trim($s);
    if ($s === '') { return null; }
    // Formato de miles con punto (X.XXX[.XXX]) → quitar los puntos.
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }

$http = new Http(UA);
$html = $http->get(URL, ['Accept: text/html']);
if ($html === null) { fail('No se pudo bajar el catálogo de Tigo.'); }

// Extraer el JSON-LD ItemList.
preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $m);
$items = [];
foreach ($m[1] as $blk) {
    $j = json_decode(trim($blk), true);
    if (!is_array($j) || ($j['@type'] ?? '') !== 'ItemList') { continue; }
    foreach ($j['itemListElement'] ?? [] as $el) {
        $prod = (($el['@type'] ?? '') === 'Product') ? $el : ($el['item'] ?? null);
        if (is_array($prod)) { $items[] = $prod; }
    }
}
line('  productos en el JSON-LD: ' . count($items));
if (!$items) { fail('No encontré el ItemList de productos (¿cambió la página?).'); }

$recs = []; $skipped = 0;
foreach ($items as $p) {
    $name  = trim((string) ($p['name'] ?? ''));
    $price = parsePrice((string) ($p['offers']['price'] ?? ''));
    if ($name === '' || $price === null || $price <= 0) { $skipped++; continue; }

    $rec = new NormalizedProduct(
        storeSlug:   'tigo',
        sku:         slugify($name),
        url:         (string) ($p['offers']['url'] ?? URL),
        title:       $name,
        brand:       brandFromName($name),
        imageUrl:    $p['image'] ?? null,
        priceNative: $price,
        currency:    (string) ($p['offers']['priceCurrency'] ?? 'NIO'),
        inStock:     true,
        taxIncluded: true,
        taxRate:     0.15,
    );
    $recs[] = $rec->toArray();
}

$res = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
if ($res['status'] !== 200) { fail("Ingesta HTTP {$res['status']}: " . $res['body']); }

line('  ✔ tigo: ' . count($recs) . ' productos' . ($skipped ? " · $skipped sin precio" : ''));
line('TOTAL enviado: ' . count($recs) . ' productos');
