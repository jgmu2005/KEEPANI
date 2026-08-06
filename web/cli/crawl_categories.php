<?php
declare(strict_types=1);

/**
 * CRAWL COMPLETO por categorías — para GitHub Actions.
 *
 * Baja el catálogo ENTERO de una tienda VTEX recorriendo sus categorías HOJA
 * (cada categoría < 2500 productos → supera el tope de la paginación plana).
 * Dedup por productId. Envía por lotes a /api/ingest.
 *
 * Usa UA de navegador + Referer + reintentos con backoff (las consultas con
 * filtro fq rate-limitean las IP de datacenter con 429).
 *
 * Uso:  php web/cli/crawl_categories.php [sinsa|siman|all]
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\VtexMapper;

const STORES = [
    'sinsa' => ['base_url' => 'https://www.sinsa.com.ni', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
    'siman' => ['base_url' => 'https://ni.siman.com',      'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
];
const PAGE = 50;
const MAX_OFFSET = 2450;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** GET con UA de navegador + Referer + reintentos (429/5xx con backoff). Devuelve array JSON o null. */
function apiGet(string $url, string $referer, int $retries = 4): ?array
{
    $lastCode = 0;
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Referer: ' . $referer],
        ]);
        $body = curl_exec($ch);
        $lastCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $lastCode >= 200 && $lastCode < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j)) {
                return $j;
            }
        }
        if ($lastCode === 429 || $lastCode >= 500 || $body === false) {
            usleep(1200000 * ($a + 1)); // 1.2s, 2.4s, 3.6s…
            continue;
        }
        break; // 4xx (no 429) → no reintentar
    }
    fwrite(STDERR, "  [fetch] falló (code $lastCode): $url\n");
    return null;
}

/** Rutas COMPLETAS (raíz→hoja) de las categorías hoja.
 *  VTEX filtra por la RUTA (C:/id1/id2/hoja/), no por el id de la hoja suelto. */
function collectLeafPaths(array $nodes, array $prefix, array &$out): void
{
    foreach ($nodes as $n) {
        if (!is_array($n) || !isset($n['id'])) {
            continue;
        }
        $path = array_merge($prefix, [(int) $n['id']]);
        if (!empty($n['children']) && is_array($n['children'])) {
            collectLeafPaths($n['children'], $path, $out);
        } else {
            $out[] = $path; // hoja → ruta completa
        }
    }
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

$which   = $argv[1] ?? 'all';
$targets = $which === 'all' ? array_keys(STORES) : [$which];

$http  = new Http(); // solo para el POST a /api/ingest
$grand = 0;

foreach ($targets as $slug) {
    if (!isset(STORES[$slug])) {
        fail("Tienda desconocida: $slug (usá sinsa, siman o all)");
    }
    $cfg     = STORES[$slug];
    $base    = rtrim($cfg['base_url'], '/');
    $referer = $base . '/';
    line("=== $slug ===");

    $tree = apiGet($base . '/api/catalog_system/pub/category/tree/50', $referer);
    if (!is_array($tree)) {
        fail("No se pudo traer el árbol de categorías de $slug");
    }
    $leaves = [];
    collectLeafPaths($tree, [], $leaves);
    line('  categorías hoja: ' . count($leaves));

    $sent = 0; $capped = 0; $fails = 0; $seen = [];

    foreach ($leaves as $idx => $path) {
        $catPath = implode('/', $path);
        $from = 0;
        while ($from <= MAX_OFFSET) {
            $to  = $from + PAGE - 1;
            $url = $base . '/api/catalog_system/pub/products/search?fq=C:/' . $catPath . '/&_from=' . $from . '&_to=' . $to;
            $data = apiGet($url, $referer);
            if ($data === null) { $fails++; break; }       // fetch falló tras reintentos
            if (count($data) === 0) { break; }              // fin de la categoría

            $recs = [];
            foreach ($data as $p) {
                $pid = (string) ($p['productId'] ?? '');
                if ($pid === '' || isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $rec = VtexMapper::map($p, $slug, $cfg['currency'], $cfg['tax_included'], $cfg['tax_rate']);
                if ($rec !== null) {
                    $recs[] = $rec->toArray();
                }
            }

            if ($recs) {
                $res = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
                if ($res['status'] !== 200) {
                    fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']);
                }
                $sent += count($recs);
            }

            if (count($data) < PAGE) { break; }
            $from += PAGE;
            if ($from > MAX_OFFSET) { $capped++; }
            usleep(400000); // 0.4s
        }

        if (($idx + 1) % 50 === 0) {
            line('  ...' . ($idx + 1) . '/' . count($leaves) . ' categorías · ' . $sent . ' productos · ' . $fails . ' fallos');
        }
    }

    line("  ✔ $slug: $sent productos únicos"
        . ($capped ? " ($capped categorías al tope 2500)" : '')
        . ($fails ? " · $fails categorías con fetch fallido" : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
