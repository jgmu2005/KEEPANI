<?php
declare(strict_types=1);

/**
 * CRAWL COMPLETO por categorías — para GitHub Actions.
 *
 * Recorre las categorías HOJA de cada tienda VTEX (usando la RUTA completa,
 * porque VTEX filtra por C:/id1/id2/hoja/, no por el id suelto), pagina los
 * productos, deduplica por productId y envía por lotes a /api/ingest.
 * Al final hace una SEGUNDA PASADA reintentando las categorías que fallaron.
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

/** GET con UA de navegador + Referer + reintentos (429/5xx con backoff). */
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
            usleep(1200000 * ($a + 1));
            continue;
        }
        break;
    }
    fwrite(STDERR, "  [fetch] falló (code $lastCode): $url\n");
    return null;
}

/** Rutas COMPLETAS (raíz→hoja) de las categorías hoja. */
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
            $out[] = $path;
        }
    }
}

/**
 * Crawlea una categoría (por su ruta) y envía sus productos.
 * @return array{sent:int, ok:bool, capped:bool}
 */
function crawlCategory(array $path, array $ctx, array &$seen): array
{
    $catPath = implode('/', $path);
    $from = 0; $sent = 0; $ok = true; $capped = false;

    while ($from <= MAX_OFFSET) {
        $to  = $from + PAGE - 1;
        $url = $ctx['base'] . '/api/catalog_system/pub/products/search?fq=C:/' . $catPath . '/&_from=' . $from . '&_to=' . $to;
        $data = apiGet($url, $ctx['referer']);
        if ($data === null) { $ok = false; break; }
        if (count($data) === 0) { break; }

        $recs = [];
        foreach ($data as $p) {
            $pid = (string) ($p['productId'] ?? '');
            if ($pid === '' || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $rec = VtexMapper::map($p, $ctx['slug'], $ctx['currency'], $ctx['tax_included'], $ctx['tax_rate']);
            if ($rec !== null) {
                $recs[] = $rec->toArray();
            }
        }

        if ($recs) {
            $res = $ctx['http']->postJson($ctx['ingestUrl'], ['items' => $recs], ['X-Api-Key: ' . $ctx['ingestKey']]);
            if ($res['status'] !== 200) {
                fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']);
            }
            $sent += count($recs);
        }

        if (count($data) < PAGE) { break; }
        $from += PAGE;
        if ($from > MAX_OFFSET) { $capped = true; }
        usleep(400000);
    }

    return ['sent' => $sent, 'ok' => $ok, 'capped' => $capped];
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
        fail("Tienda desconocida: $slug (usá sinsa, siman o all)");
    }
    $cfg  = STORES[$slug];
    $base = rtrim($cfg['base_url'], '/');
    $ctx  = $cfg + ['slug' => $slug, 'base' => $base, 'referer' => $base . '/', 'http' => $http, 'ingestUrl' => $ingestUrl, 'ingestKey' => $ingestKey];
    line("=== $slug ===");

    $tree = apiGet($base . '/api/catalog_system/pub/category/tree/50', $base . '/');
    if (!is_array($tree)) {
        fail("No se pudo traer el árbol de categorías de $slug");
    }
    $leaves = [];
    collectLeafPaths($tree, [], $leaves);
    line('  categorías hoja: ' . count($leaves));

    $sent = 0; $capped = 0; $seen = []; $failed = [];

    foreach ($leaves as $idx => $path) {
        $r = crawlCategory($path, $ctx, $seen);
        $sent += $r['sent'];
        if (!$r['ok'])   { $failed[] = $path; }
        if ($r['capped']) { $capped++; }
        if (($idx + 1) % 50 === 0) {
            line('  ...' . ($idx + 1) . '/' . count($leaves) . ' categorías · ' . $sent . ' productos · ' . count($failed) . ' fallos');
        }
    }

    // SEGUNDA PASADA: reintentar las categorías que fallaron.
    if ($failed) {
        line('  reintentando ' . count($failed) . ' categorías que fallaron…');
        sleep(3);
        $recovered = 0; $stillFail = 0;
        foreach ($failed as $path) {
            $r = crawlCategory($path, $ctx, $seen);
            $sent += $r['sent'];
            $r['ok'] ? $recovered++ : $stillFail++;
            usleep(600000);
        }
        line("  segunda pasada: $recovered recuperadas · $stillFail siguen fallando");
        $failed = $stillFail ? array_fill(0, $stillFail, 1) : [];
    }

    line("  ✔ $slug: $sent productos únicos"
        . ($capped ? " ($capped categorías al tope 2500)" : '')
        . ($failed ? ' · ' . count($failed) . ' categorías siguen fallando' : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
