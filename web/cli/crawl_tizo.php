<?php
declare(strict_types=1);

/**
 * CRAWL de Tizo (soytizo.com) — para GitHub Actions.
 *
 * Tizo es un marketplace, pero lo tratamos como UNA tienda retail (ellos cobran y
 * despachan). El catálogo vive en api.tizo.app y exige un token de invitado.
 * Recorrido:
 *   1) POST /auth/customers/guest_user?idDevice={hex}                → token
 *   2) GET  /products/catalog/lists?businessLine=B2C                 → categorías
 *   3) GET  /products/catalog/lists/{catSlug}/catalogs              → catálogos
 *   4) GET  /products/catalog/{catalogSlug}/products?page=N&size=45  → productos (paginado)
 * Dedup por productId; batch al ingest.
 *
 * Uso:  php web/cli/crawl_tizo.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\TizoMapper;

const API  = 'https://api.tizo.app/api/v1';
const BASE = 'https://www.soytizo.com';
const SLUG = 'tizo';
const CURRENCY = 'NIO';
const TAX_INCLUDED = true;
const TAX_RATE = 0.15;
const PAGE = 45;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** POST /auth/customers/guest_user (sin cuerpo, idDevice en la query) → token. */
function mintToken(): ?string
{
    $dev = bin2hex(random_bytes(16));
    $ch = curl_init(API . '/auth/customers/guest_user?idDevice=' . $dev);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) { return null; }
    $j = json_decode((string) $body, true);
    return $j['data']['token'] ?? null;
}

$TOKEN = null;

/** GET autenticado con reintentos; re-mintea el token si expira (401). */
function apiGet(string $path): ?array
{
    global $TOKEN;
    for ($a = 0; $a < 4; $a++) {
        if ($TOKEN === null) { $TOKEN = mintToken(); if ($TOKEN === null) { usleep(800000); continue; } }
        $ch = curl_init(API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: ' . $TOKEN],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            $j = json_decode((string) $body, true);
            if (is_array($j)) { return $j; }
        }
        if ($code === 401) { $TOKEN = null; continue; }        // token vencido → re-mint
        if ($code === 429 || $code >= 500 || $body === false) { usleep(1000000 * ($a + 1)); continue; }
        break;
    }
    fwrite(STDERR, "  [fetch] falló: $path\n");
    return null;
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

$TOKEN = mintToken();
if ($TOKEN === null) { fail('No se pudo generar el token de invitado de Tizo.'); }

$http = new Http();
$seen = []; $batch = []; $sent = 0;

$flush = function () use (&$batch, &$sent, $http, $ingestUrl, $ingestKey): void {
    if (!$batch) { return; }
    $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
    if ($res['status'] !== 200) { fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']); }
    $sent += count($batch);
    $batch = [];
};

line('=== tizo ===');
$cats = apiGet('/products/catalog/lists?businessLine=B2C');
$catList = $cats['data'] ?? [];
line('  categorías: ' . count($catList));

foreach ($catList as $cat) {
    $catSlug = (string) ($cat['slug'] ?? '');
    if ($catSlug === '') { continue; }
    $catalogsResp = apiGet('/products/catalog/lists/' . rawurlencode($catSlug) . '/catalogs');
    $catalogs = $catalogsResp['data'] ?? [];
    line('  · ' . $catSlug . ': ' . count($catalogs) . ' catálogos');

    foreach ($catalogs as $catalog) {
        $cSlug = (string) ($catalog['slug'] ?? '');
        if ($cSlug === '') { continue; }

        for ($page = 1; $page <= 200; $page++) {
            $r = apiGet('/products/catalog/' . rawurlencode($cSlug) . '/products?page=' . $page . '&size=' . PAGE);
            if ($r === null) { break; }
            $data = $r['data'] ?? [];
            $products = isset($data['products']) && is_array($data['products'])
                ? $data['products'] : (array_is_list($data) ? $data : []);
            if (!$products) { break; }

            foreach ($products as $p) {
                $pid = (string) ($p['productId'] ?? $p['idProduct'] ?? '');
                if ($pid === '' || isset($seen[$pid])) { continue; }
                $seen[$pid] = true;
                $rec = TizoMapper::map($p, SLUG, BASE, CURRENCY, TAX_INCLUDED, TAX_RATE);
                if ($rec !== null) { $batch[] = $rec->toArray(); }
            }
            if (count($batch) >= 300) { $flush(); }

            $tp = (int) ($r['totalPages'] ?? 1);
            if ($page >= max(1, $tp)) { break; }
            usleep(250000);
        }
        line('    ' . $cSlug . ' · acumulado: ' . ($sent + count($batch)));
    }
    $flush();
}

$flush();
line('  ✔ tizo: ' . $sent . ' productos únicos');
line('TOTAL enviado: ' . $sent . ' productos');
