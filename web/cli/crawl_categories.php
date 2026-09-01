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
    // Walmart PRIMERO: es chica (solo Electrónica, cat 13) y así queda crawleada aunque
    // el run se corte/timeout durante Sinsa/Siman (que son enormes). 'paths' fija el
    // subárbol en vez del árbol completo; el resto de Walmart queda a on-demand.
    'walmart' => ['base_url' => 'https://www.walmart.com.ni', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15, 'paths' => [[13]]],
    'sinsa' => ['base_url' => 'https://www.sinsa.com.ni', 'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
    'siman' => ['base_url' => 'https://ni.siman.com',      'currency' => 'NIO', 'tax_included' => true, 'tax_rate' => 0.15],
];
const PAGE = 50;
const MAX_OFFSET = 2450;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** GET con UA de navegador + Referer + reintentos (429/5xx con backoff). */
function apiGet(string $url, string $referer, int $retries = 4, ?int &$total = null): ?array
{
    $lastCode = 0;
    for ($a = 0; $a < $retries; $a++) {
        // VTEX manda el total del listado en el header `resources`/`Content-Range`
        // (ej. "products 0-49/830"). Lo capturamos para paginar por el total real
        // y no cortar por adivinanza (una página con <50 NO significa "última").
        $capturedTotal = null;
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
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$capturedTotal): int {
                if (preg_match('~^(?:resources|content-range)\s*:.*?/(\d+)\s*$~i', trim($line), $m)) {
                    $capturedTotal = (int) $m[1];
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $lastCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $lastCode >= 200 && $lastCode < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j)) {
                $total = $capturedTotal;
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
 * $extraFq: filtro VTEX adicional ya formateado, ej. '&fq=B:2000905' (marca),
 *           para subdividir hojas que pasan el tope de 2.500.
 * @return array{sent:int, ok:bool, capped:bool}
 */
function crawlCategory(array $path, array $ctx, array &$seen, string $extraFq = ''): array
{
    $catPath = implode('/', $path);
    $from = 0; $sent = 0; $ok = true; $capped = false; $total = null;

    while ($from <= MAX_OFFSET) {
        $to  = $from + PAGE - 1;
        $url = $ctx['base'] . '/api/catalog_system/pub/products/search?fq=C:/' . $catPath . '/' . $extraFq . '&_from=' . $from . '&_to=' . $to;
        $pageTotal = null;
        $data = apiGet($url, $ctx['referer'], 4, $pageTotal);
        if ($data === null) { $ok = false; break; }
        if ($pageTotal !== null) { $total = $pageTotal; }
        if (count($data) === 0) { break; } // sin total conocido: paginamos hasta vacío

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

        $from += PAGE;
        if ($from > MAX_OFFSET) { $capped = true; break; }
        // Terminación FIABLE por el total real de VTEX (header). Antes se cortaba
        // por "count < PAGE", pero VTEX a veces devuelve <50 en una página que NO
        // es la última (hipo / productos filtrados) → cortaba a medias (49 vs 830).
        if ($total !== null && $from >= $total) { break; }
        usleep(400000);
    }

    return ['sent' => $sent, 'ok' => $ok, 'capped' => $capped];
}

/** Clave normalizada de marca para casar facet↔brand/list (minúsculas, sin espacios extra). */
function brandKey(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)), 'UTF-8');
}

/** Mapa nombre-normalizado => brandId de toda la tienda (una sola llamada). */
function fetchBrandMap(array $ctx): array
{
    $list = apiGet($ctx['base'] . '/api/catalog_system/pub/brand/list', $ctx['referer']);
    $map = [];
    if (is_array($list)) {
        foreach ($list as $b) {
            $n = $b['name'] ?? null; $id = $b['id'] ?? null;
            if ($n !== null && $id !== null) { $map[brandKey((string) $n)] = (int) $id; }
        }
    }
    return $map;
}

/**
 * Subdivide una hoja que pasó el tope: la recorre marca por marca (fq=B:{id}),
 * porque ninguna marca dentro de una categoría suele pasar 2.500. Deduplica
 * contra $seen (lo ya enviado por la pasada plana no se reenvía).
 * @return array{sent:int, brands:int, unmapped:int, stillCapped:int}
 */
function crawlByBrand(array $path, array $ctx, array &$seen, array $brandMap): array
{
    $catPath = implode('/', $path);
    $f = apiGet($ctx['base'] . '/api/catalog_system/pub/facets/search/*?map=c&fq=C:/' . $catPath . '/', $ctx['referer']);
    $brands = (is_array($f) && !empty($f['Brands']) && is_array($f['Brands'])) ? $f['Brands'] : [];

    $sent = 0; $done = 0; $unmapped = 0; $stillCapped = 0;
    foreach ($brands as $b) {
        $qty = (int) ($b['Quantity'] ?? 0);
        if ($qty <= 0) { continue; }
        $id = $brandMap[brandKey((string) ($b['Name'] ?? ''))] ?? null;
        if ($id === null) { $unmapped += $qty; continue; } // marca sin id → se pierde (raro)
        $r = crawlCategory($path, $ctx, $seen, '&fq=B:' . $id);
        $sent += $r['sent'];
        if ($r['capped']) { $stillCapped++; } // una marca >2.500 dentro de la categoría (rarísimo)
        $done++;
        usleep(250000);
    }
    return ['sent' => $sent, 'brands' => $done, 'unmapped' => $unmapped, 'stillCapped' => $stillCapped];
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

    if (!empty($cfg['paths'])) {
        // Subárbol acotado (ej. Walmart → solo Electrónica). VTEX devuelve todo
        // el subárbol con fq=C:/13/, así que una "ruta" por raíz alcanza.
        $leaves = $cfg['paths'];
        line('  rutas acotadas: ' . count($leaves) . ' (' . implode(' ', array_map(fn($p) => 'C:/' . implode('/', $p) . '/', $leaves)) . ')');
    } else {
        $tree = apiGet($base . '/api/catalog_system/pub/category/tree/50', $base . '/');
        if (!is_array($tree)) {
            fail("No se pudo traer el árbol de categorías de $slug");
        }
        $leaves = [];
        collectLeafPaths($tree, [], $leaves);
        line('  categorías hoja: ' . count($leaves));
    }

    $sent = 0; $capped = 0; $subdivided = 0; $unmapped = 0; $seen = []; $failed = [];
    $brandMap = null; // se carga sólo si alguna hoja topa (una llamada por tienda)

    foreach ($leaves as $idx => $path) {
        $r = crawlCategory($path, $ctx, $seen);
        $sent += $r['sent'];
        if (!$r['ok'])   { $failed[] = $path; }
        if ($r['capped']) {
            $capped++;
            // Hoja con más de 2.500: rescatamos el resto subdividiendo por marca.
            if ($brandMap === null) { $brandMap = fetchBrandMap($ctx); }
            $sub = crawlByBrand($path, $ctx, $seen, $brandMap);
            $sent += $sub['sent'];
            $subdivided += $sub['sent'];
            $unmapped   += $sub['unmapped'];
            line('    ↳ C:/' . implode('/', $path) . '/ topó: +' . $sub['sent'] . ' por marca'
                . ' (' . $sub['brands'] . ' marcas'
                . ($sub['unmapped'] ? ', ' . $sub['unmapped'] . ' sin id' : '')
                . ($sub['stillCapped'] ? ', ' . $sub['stillCapped'] . ' marcas aún al tope' : '') . ')');
        }
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
        . ($capped ? " ($capped hojas topaban 2500 → +$subdivided rescatados por marca)" : '')
        . ($unmapped ? " · $unmapped sin id de marca (perdidos)" : '')
        . ($failed ? ' · ' . count($failed) . ' categorías siguen fallando' : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
