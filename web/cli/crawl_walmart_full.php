<?php
declare(strict_types=1);

/**
 * CRAWL COMPLETO de Walmart NI para el CAZAOFERTAS (liquidaciones) — GitHub Actions.
 *
 * Recorre TODO el árbol de categorías de Walmart (VTEX), pagina los productos y
 * los envía por lotes a /api/wm_ingest.php (subsistema aislado wm_*). Ese endpoint
 * guarda 1 fila por producto y registra sólo las bajas ≥30%.
 *
 * Uso:  php web/cli/crawl_walmart_full.php
 * Env:  OJO_INGEST_URL (…/api/ingest.php → derivamos wm_ingest.php), OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\VtexMapper;

const BASE = 'https://www.walmart.com.ni';
const SLUG = 'walmart';
const PAGE = 50;
const MAX_OFFSET = 2450; // VTEX corta la paginación plana ~2500 → crawl por categoría hoja
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

function apiGet(string $url, int $retries = 4): ?array
{
    $code = 0;
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Referer: ' . BASE . '/'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j)) { return $j; }
        }
        if ($code === 429 || $code >= 500 || $body === false) { usleep(1200000 * ($a + 1)); continue; }
        break;
    }
    fwrite(STDERR, "  [fetch] falló (code $code): $url\n");
    return null;
}

function collectLeafPaths(array $nodes, array $prefix, array &$out): void
{
    foreach ($nodes as $n) {
        if (!is_array($n) || !isset($n['id'])) { continue; }
        $path = array_merge($prefix, [(int) $n['id']]);
        if (!empty($n['children']) && is_array($n['children'])) { collectLeafPaths($n['children'], $path, $out); }
        else { $out[] = $path; }
    }
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }
$wmUrl = preg_replace('#/[^/]*$#', '/wm_ingest.php', $ingestUrl);

$http = new Http();

$tree = apiGet(BASE . '/api/catalog_system/pub/category/tree/50');
if (!is_array($tree)) { fail('No se pudo traer el árbol de categorías de Walmart'); }
$leaves = [];
collectLeafPaths($tree, [], $leaves);
line('categorías hoja: ' . count($leaves));

$seen = []; $sent = 0; $drops = 0; $failed = [];

function crawlCat(array $path, array $ctx, array &$seen, int &$sent, int &$drops): bool
{
    $catPath = implode('/', $path);
    $from = 0; $ok = true;
    while ($from <= MAX_OFFSET) {
        $url = BASE . '/api/catalog_system/pub/products/search?fq=C:/' . $catPath . '/&_from=' . $from . '&_to=' . ($from + PAGE - 1);
        $data = apiGet($url);
        if ($data === null) { $ok = false; break; }
        if (count($data) === 0) { break; }

        $recs = [];
        foreach ($data as $p) {
            $pid = (string) ($p['productId'] ?? '');
            if ($pid === '' || isset($seen[$pid])) { continue; }
            $seen[$pid] = true;
            $rec = VtexMapper::map($p, SLUG, 'NIO', true, 0.15);
            if ($rec !== null) { $recs[] = $rec->toArray(); }
        }
        if ($recs) {
            $res = $ctx['http']->postJson($ctx['wmUrl'], ['items' => $recs], ['X-Api-Key: ' . $ctx['key']]);
            if ($res['status'] !== 200) { fail("wm_ingest falló (HTTP {$res['status']}): " . $res['body']); }
            $j = json_decode($res['body'], true);
            $sent  += (int) ($j['seen'] ?? count($recs));
            $drops += (int) ($j['drops'] ?? 0);
        }
        if (count($data) < PAGE) { break; }
        $from += PAGE;
        usleep(400000);
    }
    return $ok;
}

$ctx = ['http' => $http, 'wmUrl' => $wmUrl, 'key' => $ingestKey];
foreach ($leaves as $idx => $path) {
    if (!crawlCat($path, $ctx, $seen, $sent, $drops)) { $failed[] = $path; }
    if (($idx + 1) % 50 === 0) { line('  ...' . ($idx + 1) . '/' . count($leaves) . ' cats · ' . $sent . ' productos · ' . $drops . ' bajas · ' . count($failed) . ' fallos'); }
}

// Segunda pasada de las que fallaron.
if ($failed) {
    line('reintentando ' . count($failed) . ' categorías…');
    sleep(3);
    $still = [];
    foreach ($failed as $path) { if (!crawlCat($path, $ctx, $seen, $sent, $drops)) { $still[] = $path; } usleep(600000); }
    line('segunda pasada: ' . (count($failed) - count($still)) . ' recuperadas · ' . count($still) . ' siguen fallando');
}

line("TOTAL: $sent productos · $drops bajas ≥30% registradas · " . count($seen) . ' únicos');
