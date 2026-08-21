<?php
declare(strict_types=1);

/**
 * CRON server-side — crawl de una tienda WooCommerce DESDE FatCow.
 *
 * Para tiendas cuyo Cloudflare bloquea las IPs de GitHub Actions (403) pero deja
 * pasar la IP del server (ej. fitshop). Corre en FatCow, baja el WooCommerce Store
 * API e ingesta directo (sin POST a /api/ingest). Lo dispara cron-job.org.
 *
 *   GET /cron/crawl_woo_server.php?store=fitshop   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\WooMapper;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$slug = preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['store'] ?? 'fitshop'));

$st = $db->prepare("SELECT base_url, currency, tax_included, tax_rate FROM stores WHERE slug = ? AND platform = 'woocommerce' AND is_active = 1");
$st->execute([$slug]);
$store = $st->fetch();
if (!$store) {
    out(404, ['ok' => false, 'error' => "Tienda WooCommerce '$slug' no encontrada"]);
}
$base        = rtrim((string) $store['base_url'], '/');
$currency    = (string) ($store['currency'] ?? 'NIO');
$taxIncluded = (bool) ($store['tax_included'] ?? 1);
$taxRate     = (float) ($store['tax_rate'] ?? 0.15);

$ingest = new IngestService($db);
$seen = []; $sent = 0; $pages = 0;

for ($page = 1; $page <= 200; $page++) {
    $ch = curl_init($base . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 40, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        out(502, ['ok' => false, 'error' => "Fetch falló (HTTP $code) en página $page", 'sent' => $sent]);
    }
    $products = json_decode((string) $body, true);
    if (!is_array($products) || count($products) === 0) { break; }
    $pages++;

    $batch = [];
    foreach ($products as $p) {
        $h = (string) ($p['slug'] ?? '');
        if ($h === '' || isset($seen[$h])) { continue; }
        $seen[$h] = true;
        $rec = WooMapper::map($p, $slug, $currency, $taxIncluded, $taxRate);
        if ($rec !== null) { $batch[] = $rec->toArray(); }
    }
    if ($batch) {
        $ingest->ingest($batch);
        $sent += count($batch);
    }
    if (count($products) < 100) { break; }
    usleep(300000);
}

out(200, ['ok' => true, 'store' => $slug, 'pages' => $pages, 'sent' => $sent]);
