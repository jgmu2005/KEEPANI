<?php
declare(strict_types=1);

/**
 * CRAWL de tiendas Magento 2 con GraphQL — para GitHub Actions.
 *
 * Magento expone un GraphQL abierto en {base}/graphql. Recorrido:
 *   1) categoryList(url_path) → uid de la categoría a crawlear
 *   2) products(filter:{category_uid}) paginado → items con precio/stock/imagen
 * Se normaliza con MagentoMapper y se manda al ingest. Trackea por url_key.
 *
 * Uso:  php web/cli/crawl_magento.php [samsung|all]
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\MagentoAdapter;
use OjoAlPrecio\Web\Fetch\MagentoMapper;

const STORES = [
    'samsung' => [
        'base_url'     => 'https://shop.samsung.com/latin/ni',
        'store_code'   => 'ni',
        'brand'        => 'Samsung',
        'currency'     => 'USD',
        'tax_included' => true,
        'tax_rate'     => 0.15,
        // Categorías (url_path) a crawlear. Sumar tablets/wearables si se quiere.
        'categories'   => ['shop/smartphones'],
    ],
];
const PAGE      = 50;
const MAX_PAGES = 60;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** POST GraphQL con reintentos (429/5xx). Devuelve el JSON decodificado o null. */
function gql(string $endpoint, string $query, string $storeCode, int $retries = 4): ?array
{
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['query' => $query], JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Store: ' . $storeCode],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j) && empty($j['errors'])) { return $j; }
            if (is_array($j) && !empty($j['errors'])) {
                fwrite(STDERR, "  [gql] error: " . ($j['errors'][0]['message'] ?? '?') . "\n");
                return null;
            }
        }
        if ($code === 429 || $code >= 500 || $body === false) { usleep(1000000 * ($a + 1)); continue; }
        break;
    }
    fwrite(STDERR, "  [gql] falló (HTTP $code)\n");
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
    $cfg      = STORES[$slug];
    $endpoint = rtrim($cfg['base_url'], '/') . '/graphql';
    line("=== $slug ===");

    $seen = []; $sent = 0;
    foreach ($cfg['categories'] as $path) {
        // 1) uid de la categoría por url_path
        $cr  = gql($endpoint, '{categoryList(filters:{url_path:{eq:"' . $path . '"}}){uid name}}', $cfg['store_code']);
        $uid = $cr['data']['categoryList'][0]['uid'] ?? null;
        if ($uid === null) { line("  ⚠ categoría '$path' sin uid; salto."); continue; }
        line("  · $path (uid=$uid)");

        // 2) productos paginados
        for ($page = 1; $page <= MAX_PAGES; $page++) {
            $q = '{products(filter:{category_uid:{eq:"' . $uid . '"}},pageSize:' . PAGE . ',currentPage:' . $page . ')'
               . '{total_count page_info{total_pages} items{' . MagentoAdapter::FIELDS . '}}}';
            $r     = gql($endpoint, $q, $cfg['store_code']);
            $items = $r['data']['products']['items'] ?? [];
            if (!$items) { break; }

            $recs = [];
            foreach ($items as $it) {
                $key = (string) ($it['url_key'] ?? '');
                if ($key === '' || isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $rec = MagentoMapper::map($it, $slug, $cfg['base_url'], $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate'], $cfg['brand']);
                if ($rec !== null) { $recs[] = $rec->toArray(); }
            }
            if ($recs) {
                $res = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
                if ($res['status'] !== 200) { fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']); }
                $sent += count($recs);
            }

            $totalPages = (int) ($r['data']['products']['page_info']['total_pages'] ?? 1);
            line("    ...página $page/$totalPages · " . count($items) . ' items · ' . $sent . ' enviados');
            if ($page >= max(1, $totalPages)) { break; }
            usleep(400000);
        }
    }

    line("  ✔ $slug: $sent productos únicos");
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
