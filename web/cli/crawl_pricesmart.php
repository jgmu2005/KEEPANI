<?php
declare(strict_types=1);

/**
 * CRAWL de PriceSmart Nicaragua (Bloomreach Discovery) — GitHub Actions.
 * Solo las categorías que valen la pena: Electrónicos, Deportes, Ferretería, Oficina.
 *
 * Consulta el BFF /api/br_discovery/getProductsByKeyword (credenciales Bloomreach
 * fijas, sin sesión), pagina, mapea y envía por lotes a /api/ingest.
 *
 * Uso:  php web/cli/crawl_pricesmart.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\PriceSmartMapper;

const BASE = 'https://www.pricesmart.com';
const BFF  = BASE . '/api/br_discovery/getProductsByKeyword';
const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const PAGE = 100;
const FL   = 'pid,title,brand,slug,master_sku,price_NI,original_price_without_saving_NI,saving_amount_NI,inventory_NI,availability_NI,thumb_image,currency,fractionDigits';

// Credenciales Bloomreach de PriceSmart (públicas, embebidas en su front).
const BR = [
    'account_id' => '7024',
    'auth_key'   => 'ev7libhybjg5h1d1',
    'domain_key' => 'pricesmart_bloomreach_io_es',
    'view_id'    => 'NI',
];

// code => URL de categoría (para el campo url/ref requerido).
const CATS = [
    'E10D24' => BASE . '/es-ni/categoria/Electronicos-E10D24/E10D24',
    'S30D26' => BASE . '/es-ni/categoria/Deportes-y-fitness-S30D26/S30D26',
    'H10D21' => BASE . '/es-ni/categoria/Ferreteria-y-mejoras-al-hogar-H10D21/H10D21',
    'O10D25' => BASE . '/es-ni/categoria/Oficina-O10D25/O10D25',
];
const TAX_INCLUDED = false; // PriceSmart: precios sin IVA
const TAX_RATE     = 0.15;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }

$http = new Http(UA);
$grand = 0;

foreach (CATS as $code => $catUrl) {
    line("=== $code ===");
    $start = 0; $total = null; $sent = 0;

    while (true) {
        $payload = [[
            'q' => $code, 'search_type' => 'category', 'start' => $start, 'rows' => PAGE, 'fq' => [],
            'account_id' => BR['account_id'], 'auth_key' => BR['auth_key'],
            'domain_key' => BR['domain_key'], 'view_id' => BR['view_id'],
            'request_id' => 1786053095902 + $start, '_br_uid_2' => 'uid=1:v=15.0:ts=1786052950414:hc=2',
            'url' => $catUrl, 'fl' => FL,
        ]];
        $res = $http->postJson(BFF, $payload, ['Referer: ' . $catUrl, 'Origin: ' . BASE]);
        if ($res['status'] !== 200) { fail("BFF HTTP {$res['status']} en $code: " . substr($res['body'], 0, 200)); }

        $j = json_decode($res['body'], true);
        $docs = $j['response']['docs'] ?? [];
        $total = (int) ($j['response']['numFound'] ?? 0);
        if (!$docs) { break; }

        $recs = [];
        foreach ($docs as $d) {
            $rec = PriceSmartMapper::map($d, 'pricesmart', BASE, 'NIO', TAX_INCLUDED, TAX_RATE);
            if ($rec !== null) { $recs[] = $rec->toArray(); }
        }
        if ($recs) {
            $ing = $http->postJson($ingestUrl, ['items' => $recs], ['X-Api-Key: ' . $ingestKey]);
            if ($ing['status'] !== 200) { fail("Ingesta HTTP {$ing['status']}: " . $ing['body']); }
            $sent += count($recs);
        }

        $start += PAGE;
        if ($start >= $total) { break; }
        usleep(400000);
    }

    line("  ✔ $code: $sent productos (de $total)");
    $grand += $sent;
}

line("TOTAL enviado: $grand productos");
