<?php
declare(strict_types=1);

/**
 * CRAWL COMPLETO por categorías — para GitHub Actions.
 *
 * Baja el catálogo ENTERO de una tienda VTEX recorriendo sus categorías HOJA
 * (cada categoría tiene < 2500 productos → se supera el tope de la paginación plana).
 * Dedup por productId. Envía por lotes a /api/ingest.
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
const MAX_OFFSET = 2450; // tope de la paginación plana de VTEX
const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** Recorre el árbol y junta los ids de las categorías HOJA (sin hijas). */
function collectLeaves(array $nodes, array &$out): void
{
    foreach ($nodes as $n) {
        if (!is_array($n) || !isset($n['id'])) {
            continue;
        }
        if (empty($n['hasChildren'])) {
            $out[] = (int) $n['id'];
        }
        if (!empty($n['children']) && is_array($n['children'])) {
            collectLeaves($n['children'], $out);
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

$http     = new Http();               // UA de bot para productos
$treeHttp = new Http(BROWSER_UA);     // UA de navegador para el árbol (evita 429)
$grand = 0;

foreach ($targets as $slug) {
    if (!isset(STORES[$slug])) {
        fail("Tienda desconocida: $slug (usá sinsa, siman o all)");
    }
    $cfg  = STORES[$slug];
    $base = rtrim($cfg['base_url'], '/');
    line("=== $slug ===");

    $tree = $treeHttp->getJson($base . '/api/catalog_system/pub/category/tree/50', ['Referer: ' . $base . '/']);
    if (!is_array($tree)) {
        fail("No se pudo traer el árbol de categorías de $slug");
    }
    $leaves = [];
    collectLeaves($tree, $leaves);
    $leaves = array_values(array_unique($leaves));
    line('  categorías hoja: ' . count($leaves));

    $sent = 0; $capped = 0; $seen = [];

    foreach ($leaves as $idx => $catId) {
        $from = 0;
        while ($from <= MAX_OFFSET) {
            $to  = $from + PAGE - 1;
            $url = $base . '/api/catalog_system/pub/products/search?fq=C:/' . $catId . '/&_from=' . $from . '&_to=' . $to;
            $data = $http->getJson($url);
            if (!is_array($data) || count($data) === 0) {
                break; // fallo o fin de la categoría
            }

            $recs = [];
            foreach ($data as $p) {
                $pid = (string) ($p['productId'] ?? '');
                if ($pid === '' || isset($seen[$pid])) {
                    continue; // dedup: ya lo enviamos desde otra categoría
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

            if (count($data) < PAGE) {
                break; // última página de esta categoría
            }
            $from += PAGE;
            if ($from > MAX_OFFSET) {
                $capped++; // categoría con >2500: quedó tope (raro en hojas)
            }
            usleep(300000); // 0.3s: cortés con la tienda
        }

        if (($idx + 1) % 50 === 0) {
            line('  ...' . ($idx + 1) . '/' . count($leaves) . ' categorías · ' . $sent . ' productos');
        }
    }

    line("  ✔ $slug: $sent productos únicos" . ($capped ? " ($capped categorías tocaron el tope 2500)" : ''));
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
