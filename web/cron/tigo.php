<?php
declare(strict_types=1);

/**
 * CRON de Tigo (catálogo prepago) — corre en FatCow (Tigo bloquea la IP de
 * GitHub). Lo dispara cron-job.org.  Header: X-Api-Key: <ingest_api_key>
 *
 * Baja el HTML del catálogo, extrae el JSON-LD ItemList e ingiere IN-PROCESS
 * (sin dar la vuelta por HTTP). Requiere la tienda 'tigo' (migración 023).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\NormalizedProduct;

const URL = 'https://www.tigo.com.ni/catalogo-prepago';
const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function t_slug(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-') ?: 'producto';
}
function t_brand(string $name): ?string
{
    $n = mb_strtolower($name, 'UTF-8');
    $map = ['iphone'=>'Apple','apple'=>'Apple','samsung'=>'Samsung','galaxy'=>'Samsung','honor'=>'Honor',
        'huawei'=>'Huawei','xiaomi'=>'Xiaomi','redmi'=>'Xiaomi','poco'=>'Xiaomi','motorola'=>'Motorola',
        'tecno'=>'Tecno','infinix'=>'Infinix','itel'=>'itel','nokia'=>'Nokia','oppo'=>'Oppo',
        'realme'=>'Realme','zte'=>'ZTE','alcatel'=>'Alcatel'];
    foreach ($map as $k => $v) { if (str_contains($n, $k)) { return $v; } }
    return null;
}
function t_price(string $s): ?float
{
    $s = trim($s);
    if ($s === '') { return null; }
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) { $s = str_replace('.', '', $s); }
    return is_numeric($s) ? (float) $s : null;
}

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$http = new Http(UA);
$html = $http->get(URL, ['Accept: text/html']);
if ($html === null) {
    out(502, ['ok' => false, 'error' => 'No se pudo bajar el catálogo de Tigo (¿bloqueo de IP también en FatCow?)']);
}

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
if (!$items) {
    out(502, ['ok' => false, 'error' => 'No encontré el ItemList (¿cambió la página o vino sin JSON-LD?)']);
}

$recs = [];
foreach ($items as $p) {
    $name  = trim((string) ($p['name'] ?? ''));
    $price = t_price((string) ($p['offers']['price'] ?? ''));
    if ($name === '' || $price === null || $price <= 0) { continue; }
    $recs[] = (new NormalizedProduct(
        storeSlug: 'tigo', sku: t_slug($name), url: (string) ($p['offers']['url'] ?? URL),
        title: $name, brand: t_brand($name), imageUrl: $p['image'] ?? null,
        priceNative: $price, currency: (string) ($p['offers']['priceCurrency'] ?? 'NIO'),
        inStock: true, taxIncluded: true, taxRate: 0.15,
    ))->toArray();
}

$res = (new IngestService($db))->ingest($recs);
out(200, ['ok' => true, 'found' => count($items)] + $res);
