<?php
declare(strict_types=1);

/**
 * CRAWL de Comtech — para GitHub Actions.
 *
 * Comtech corre sobre la plataforma Online40 (Blazor WASM). El listado del sitio
 * es client-side, pero su API de búsqueda IGNORA el término y devuelve el CATÁLOGO
 * COMPLETO en una sola llamada, con precio/stock/marca/imagen:
 *   GET {base}/api/Search/COMTECH/es/{cualquier-cosa}  →  [ {ProductCode, Price, ...}, ... ]
 *
 * El nombre suele venir vacío (igual que Copasa) → usamos "Marca + Modelo".
 * La ficha sí trae OG meta de precio, así que el on-demand va por OgMetaAdapter.
 *
 * Uso:  php web/cli/crawl_comtech.php
 * Env:  OJO_INGEST_URL, OJO_INGEST_KEY
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\NormalizedProduct;

const BASE   = 'https://www.comtech.com.ni';
const SLUG   = 'comtech';
const IMG_BASE = 'https://s3.amazonaws.com/';   // ResourceLink → bucket S3 online.storage
const CURRENCY = 'NIO';
const TAX_INCLUDED = true;   // los precios de la ficha ya incluyen IVA
const TAX_RATE = 0.15;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

/** GET JSON con UA de navegador + Referer (la API es de Online40) + reintentos. */
function apiGet(string $url, int $retries = 4): ?array
{
    $lastCode = 0;
    for ($a = 0; $a < $retries; $a++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Referer: ' . BASE . '/'],
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

line('=== comtech ===');
// El término es irrelevante: la API devuelve todo el catálogo.
$data = apiGet(BASE . '/api/Search/COMTECH/es/a');
if ($data === null) {
    fail('No se pudo leer el Search API de Comtech.');
}
line('  catálogo recibido: ' . count($data) . ' productos');

$http = new Http();
$batch = []; $sent = 0; $seen = []; $noPrice = 0;

$flush = function () use (&$batch, &$sent, $http, $ingestUrl, $ingestKey) {
    if (!$batch) { return; }
    $res = $http->postJson($ingestUrl, ['items' => $batch], ['X-Api-Key: ' . $ingestKey]);
    if ($res['status'] !== 200) {
        fail("Ingesta falló (HTTP {$res['status']}): " . $res['body']);
    }
    $sent += count($batch);
    $batch = [];
};

foreach ($data as $p) {
    $code = (string) ($p['ProductCode'] ?? '');
    if ($code === '' || isset($seen[$code])) { continue; }
    $seen[$code] = true;

    $price = isset($p['Price']) ? (float) $p['Price'] : null;
    if ($price === null || $price <= 0) { $noPrice++; continue; }

    // Nombre: suele venir vacío → "Marca + Modelo" (como Copasa).
    $name = trim((string) ($p['ProductName'] ?? ''));
    if ($name === '') {
        $name = trim(((string) ($p['Brand'] ?? '')) . ' ' . ((string) ($p['Model'] ?? '')));
    }
    if ($name === '') { $name = 'Comtech ' . $code; }

    $img = null;
    if (!empty($p['ResourceLink'])) {
        $img = IMG_BASE . ltrim((string) $p['ResourceLink'], '/');
    } elseif (!empty($p['ImageFileName'])) {
        $img = IMG_BASE . 'online.storage/COMTECH/Products/' . $p['ImageFileName'] . '.webp';
    }

    $rec = new NormalizedProduct(
        storeSlug:   SLUG,
        sku:         $code,
        url:         BASE . '/product/' . rawurlencode($code),
        title:       $name,
        brand:       !empty($p['Brand']) ? (string) $p['Brand'] : null,
        imageUrl:    $img,
        priceNative: $price,
        currency:    CURRENCY,
        inStock:     !empty($p['InStock']),
        taxIncluded: TAX_INCLUDED,
        taxRate:     TAX_RATE,
    );
    $batch[] = $rec->toArray();
    if (count($batch) >= 200) { $flush(); }
}
$flush();

line("  ✔ comtech: $sent productos enviados · $noPrice sin precio");
line("TOTAL enviado: $sent productos");
