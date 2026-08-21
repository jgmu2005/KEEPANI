<?php
declare(strict_types=1);

/**
 * CRAWL de tiendas WooCommerce — para GitHub Actions.
 *
 * Usa el Store API público (sin auth):
 *   GET {base}/wp-json/wc/store/v1/products?per_page=100&page=N
 * Trae precio (unidades menores), regular_price, stock, slug, permalink, marca.
 * Se pagina hasta que una página venga vacía, se normaliza y se manda al ingest.
 *
 * Uso:  php web/cli/crawl_woocommerce.php [etech|pcsystemni|all]
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\WooMapper;

const STORES = [
    'etech'      => ['base_url' => 'https://etech.com.ni',   'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
    'pcsystemni' => ['base_url' => 'https://pcsystemni.com', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
    'fitshop'    => ['base_url' => 'https://fitshop.com.ni', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
];
const PAGE      = 100; // máximo del Store API
const MAX_PAGES = 120; // tope de seguridad
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** GET con UA de navegador + reintentos (429/5xx con backoff). */
function apiGet(string $url, int $retries = 4): ?array
{
    $lastCode = 0;
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $lastCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $lastCode >= 200 && $lastCode < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j)) { return $j; }
        }
        if ($lastCode === 429 || $lastCode >= 500 || $body === false) {
            usleep(1200000 * ($a + 1));
            continue;
        }
        break;
    }
    fwrite(STDERR, "  [fetch] falló (code $lastCode): $url\n");
    return null;
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

$which   = $argv[1] ?? 'all';
$targets = $which === 'all' ? array_keys(STORES) : [$which];

$http  = new Http();
$grand = 0;

foreach ($targets as $slug) {
    if (!isset(STORES[$slug])) {
        fail("Tienda desconocida: $slug (usá " . implode(', ', array_keys(STORES)) . " o all)");
    }
    $cfg  = STORES[$slug];
    $base = rtrim($cfg['base_url'], '/');
    line("=== $slug ===");

    $sent = 0; $seen = [];
    for ($page = 1; $page <= MAX_PAGES; $page++) {
        $products = apiGet($base . '/wp-json/wc/store/v1/products?per_page=' . PAGE . '&page=' . $page);
        if ($products === null) { line("  ⚠ página $page falló; corto acá."); break; }
        if (count($products) === 0) { break; }

        $recs = [];
        foreach ($products as $p) {
            $handle = (string) ($p['slug'] ?? '');
            if ($handle === '' || isset($seen[$handle])) { continue; }
            $seen[$handle] = true;
            $rec = WooMapper::map($p, $slug, $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate']);
            if ($rec !== null) { $recs[] = $rec->toArray(); }
        }

        if ($recs) {
            $res = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
            if ($res['status'] !== 200) {
                fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']);
            }
            $sent += count($recs);
        }

        line('  ...página ' . $page . ' · ' . count($products) . ' productos · ' . $sent . ' enviados');
        if (count($products) < PAGE) { break; }
        usleep(400000);
    }

    line("  ✔ $slug: $sent productos únicos");
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
