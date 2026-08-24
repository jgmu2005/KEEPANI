<?php
declare(strict_types=1);

/**
 * CRON server-side — crawl de una tienda WooCommerce DESDE FatCow.
 *
 * Para tiendas cuyo Cloudflare bloquea las IPs de GitHub Actions (403) pero deja
 * pasar la IP del server (ej. fitshop). Corre en FatCow, baja el WooCommerce Store
 * API e ingesta directo (sin POST a /api/ingest). Lo dispara cron-job.org.
 *
 * Acepta una o varias tiendas separadas por coma (catálogos chicos → un cron):
 *   GET /cron/crawl_woo_server.php?store=fitshop
 *   GET /cron/crawl_woo_server.php?store=fitshop,fetesa,telcmax
 *   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\WooMapper;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);
// Si cron-job.org corta la conexión por timeout (p.ej. 30s), que PHP NO aborte:
// seguimos ingiriendo por detrás hasta terminar (hasta donde el hosting lo permita).
@ignore_user_abort(true);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Acepta 1 o varias tiendas: ?store=fitshop  o  ?store=fitshop,fetesa,telcmax
// (catálogos chicos → un solo cron las baja todas). Cada tienda va aislada:
// si una falla (403/timeout), se registra el error y se sigue con las demás.
$raw   = (string) ($_GET['store'] ?? 'fitshop');
$slugs = array_values(array_filter(array_map(
    static fn($s) => preg_replace('/[^a-z0-9_-]/', '', $s),
    explode(',', $raw)
)));
if (!$slugs) { out(400, ['ok' => false, 'error' => 'Falta el parámetro store']); }

$ingest  = new IngestService($db);
$results  = [];
$grand    = 0;

foreach ($slugs as $slug) {
    $st = $db->prepare("SELECT base_url, currency, tax_included, tax_rate FROM stores WHERE slug = ? AND platform = 'woocommerce' AND is_active = 1");
    $st->execute([$slug]);
    $store = $st->fetch();
    if (!$store) {
        $results[] = ['store' => $slug, 'ok' => false, 'error' => 'no encontrada'];
        continue;
    }
    $base        = rtrim((string) $store['base_url'], '/');
    $currency    = (string) ($store['currency'] ?? 'NIO');
    $taxIncluded = (bool) ($store['tax_included'] ?? 1);
    $taxRate     = (float) ($store['tax_rate'] ?? 0.15);

    $seen = []; $sent = 0; $pages = 0; $error = null;

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
            $error = "Fetch falló (HTTP $code) en página $page";
            break;
        }
        $products = json_decode((string) $body, true);
        if (!is_array($products) || count($products) === 0) { break; }
        $pages++;

        $batch = [];
        foreach ($products as $p) {
            $h = WooMapper::handle($p);
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

    $grand += $sent;
    $results[] = $error === null
        ? ['store' => $slug, 'ok' => true, 'pages' => $pages, 'sent' => $sent]
        : ['store' => $slug, 'ok' => false, 'pages' => $pages, 'sent' => $sent, 'error' => $error];
}

$allOk = !array_filter($results, static fn($r) => !$r['ok']);
out($allOk ? 200 : 207, ['ok' => $allOk, 'total_sent' => $grand, 'stores' => $results]);
