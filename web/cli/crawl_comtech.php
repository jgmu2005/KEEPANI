<?php
declare(strict_types=1);

/**
 * CRAWL de Comtech — para GitHub Actions.
 *
 * Comtech corre sobre Online40 (Blazor WASM). Tiene DOS índices y no coinciden:
 *
 *   - /api/Search/COMTECH/es/{term}         → catálogo "default" en 1 llamada,
 *                                             pero DESACTUALIZADO (ej. traía 7 CDP
 *                                             cuando el sitio muestra 28). Ignora
 *                                             el término. Sirve sólo de baseline.
 *   - /api/BranchOfficeSearch/COMTECH/{br}/True/{term}/es/False/{size}/{page}/
 *                                           → índice FRESCO de la sucursal online
 *                                             (precio/stock reales). Es un BUSCADOR:
 *                                             respeta el término pero topa ~88
 *                                             resultados por término, sin paginar.
 *
 * Estrategia (unión, mejora estricta): recorremos los nombres de categoría/marca de
 * AllCategories como términos del buscador fresco y deduplicamos por ProductCode
 * (esos ganan, traen precio fresco); luego rellenamos con /api/Search los que no
 * salieron en ninguna categoría. Así pasamos de ~1414 (7 CDP) a ~1664 (28 CDP)
 * sin perder nada de lo que ya traíamos.
 *
 * El nombre suele venir vacío (igual que Copasa) → "ImageAlternativeText", si no
 * "Marca + Modelo".
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
const BRANCH_FALLBACK = '828726fb-5689-47f9-8e15-951cd943dfdd'; // sucursal online default
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

/** Devuelve la lista de productos de una respuesta que puede venir como
 *  {Products:[...]} (BranchOfficeSearch) o como array plano (/api/Search). */
function productsOf(?array $r): array
{
    if ($r === null) { return []; }
    if (isset($r['Products']) && is_array($r['Products'])) { return $r['Products']; }
    return array_is_list($r) ? $r : [];
}

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$ingestKey = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $ingestKey === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY en el entorno.');
}

line('=== comtech ===');

// 1) Sucursal online (auto-descubrir; el campo trae el typo "BrachOfficeId").
$br = apiGet(BASE . '/api/BranchOffice/Default/COMTECH/OrderPicking');
$branch = (string) ($br['BrachOfficeId'] ?? $br['BranchOfficeId'] ?? '');
if ($branch === '') { $branch = BRANCH_FALLBACK; }
line('  sucursal: ' . $branch);

// 2) Términos = nombres de categoría/marca del catálogo.
$cats = apiGet(BASE . '/api/Catalog/AllCategories/COMTECH/es');
$terms = [];
foreach (($cats ?? []) as $c) {
    $name = trim((string) ($c['CategoryName'] ?? ''));
    if ($name !== '') { $terms[mb_strtolower($name)] = $name; }
}
$terms = array_values($terms);
line('  categorías/marcas a buscar: ' . count($terms));

// 3) Índice FRESCO: BranchOfficeSearch por cada término, dedup por ProductCode.
$byCode = []; $capped = 0;
foreach ($terms as $t) {
    $url = BASE . '/api/BranchOfficeSearch/COMTECH/' . $branch . '/True/'
         . rawurlencode($t) . '/es/False/100/1/';
    $prods = productsOf(apiGet($url));
    if (count($prods) >= 88) { $capped++; } // término topado: la unión con /api/Search lo rellena
    foreach ($prods as $p) {
        $code = (string) ($p['ProductCode'] ?? '');
        if ($code !== '' && !isset($byCode[$code])) { $byCode[$code] = $p; }
    }
    usleep(150000);
}
line('  frescos (por categoría): ' . count($byCode) . ($capped ? " · $capped términos topados en 88" : ''));

// 4) Relleno con el índice viejo: agrega SOLO los que no salieron en ninguna categoría.
$old = productsOf(apiGet(BASE . '/api/Search/COMTECH/es/a'));
$added = 0;
foreach ($old as $p) {
    $code = (string) ($p['ProductCode'] ?? '');
    if ($code !== '' && !isset($byCode[$code])) { $byCode[$code] = $p; $added++; }
}
line('  + relleno /api/Search: +' . $added . ' → total ' . count($byCode) . ' productos');
if (!$byCode) { fail('No se obtuvo ningún producto de Comtech (¿cambió la API?).'); }

// 5) Mapear e ingestar (tolerante a blips del ingest compartido).
$http = new Http();
$batch = []; $noPrice = 0; $st = ['sent' => 0, 'lost' => 0, 'consec' => 0];

$flush = function () use (&$batch, &$st, $http, $ingestUrl, $ingestKey): void {
    $r = oap_ingest_batch($http, $ingestUrl, $ingestKey, $batch, $st);
    if ($r === 'auth') { fail('Ingesta rechazada (HTTP 401/403): revisá el secret OJO_INGEST_KEY.'); }
    if ($r === 'down') { fail('Ingesta caída: 8 lotes seguidos fallaron. Reintentá el run.'); }
    $batch = [];
};

foreach ($byCode as $code => $p) {
    $price = isset($p['Price']) ? (float) $p['Price'] : null;
    if ($price === null || $price <= 0) { $noPrice++; continue; }

    // Nombre: ProductName viene SIEMPRE vacío en comtech. El mejor texto descriptivo
    // es ImageAlternativeText (~73% lo trae, ej. "AFEITADORA ELECTRICA PARA DAMA
    // MULTILASER"); es clave para el clasificador de categorías y para el display.
    // Si no, "Marca + Modelo", y de último "Comtech {code}".
    $name = trim((string) ($p['ProductName'] ?? ''));
    if ($name === '') { $name = trim((string) ($p['ImageAlternativeText'] ?? '')); }
    if ($name === '') {
        $name = trim(((string) ($p['Brand'] ?? '')) . ' ' . ((string) ($p['Model'] ?? '')));
    }
    if ($name === '' || $name === '.') { $name = 'Comtech ' . $code; }

    // Sólo las imágenes del tenant COMTECH son públicas en S3. Algunos productos
    // referencian el storage de OTRO tenant (ej. LBRRRCL) que responde 403: para
    // esos dejamos la imagen en null (placeholder limpio, no imagen rota).
    $img = null;
    $rl  = (string) ($p['ResourceLink'] ?? '');
    if ($rl !== '') {
        if (strpos($rl, '/COMTECH/') !== false) {
            $img = IMG_BASE . ltrim($rl, '/');
        }
    } elseif (!empty($p['ImageFileName'])) {
        $img = IMG_BASE . 'online.storage/COMTECH/Products/' . $p['ImageFileName'] . '.webp';
    }

    $rec = new NormalizedProduct(
        storeSlug:   SLUG,
        sku:         (string) $code,
        url:         BASE . '/product/' . rawurlencode((string) $code),
        title:       $name,
        brand:       (!empty($p['Brand']) && $p['Brand'] !== '.') ? (string) $p['Brand'] : null,
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

if ($byCode && $st['sent'] === 0) { fail('No se ingestó ningún producto (el ingest no respondió 200).'); }
line("  ✔ comtech: {$st['sent']} productos enviados · $noPrice sin precio"
    . ($st['lost'] ? " · {$st['lost']} perdidos (se recuperan el próximo run)" : ''));
line("TOTAL enviado: {$st['sent']} productos");
