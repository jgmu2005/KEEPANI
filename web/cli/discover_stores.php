<?php
declare(strict_types=1);

/**
 * DESCUBRIDOR de tiendas online de Nicaragua.
 *
 * Toma dominios candidatos y a cada uno le prueba las "huellas" de e-commerce:
 *   - WooCommerce:  GET /wp-json/wc/store/v1/products?per_page=1   (200 + array JSON)
 *   - Shopify:      GET /products.json?limit=1                     (200 + {products:[...]})
 * Devuelve un TSV:  dominio <tab> plataforma <tab> productos
 *
 * Origen de dominios (por orden de preferencia):
 *   - Sin argumento: los baja de COMMON CRAWL (índice de la web). Consulta todas
 *     las URLs bajo el TLD .ni y las deduplica a host. Es la fuente por defecto
 *     porque crt.sh suele estar caído (502). Si Common Crawl no responde, cae a crt.sh.
 *   - Argumento 'crt': fuerza crt.sh (Certificate Transparency).
 *   - Argumento = archivo: un dominio por línea (ej. de un directorio). Útil offline.
 *
 * Uso:  php web/cli/discover_stores.php [dominios.txt|crt]
 */

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const CONCURRENCY = 20;
const PATTERNS = ['%.com.ni', '%.ni', '%nicaragua%', '%nic.com', '%ni.com'];

function err(string $s): void { fwrite(STDERR, $s . "\n"); }

function httpGet(string $url, int $timeout = 60): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
}

/** Dominios candidatos desde crt.sh (con reintentos por sus 502 frecuentes). */
function crtDomains(): array
{
    $set = [];
    foreach (PATTERNS as $q) {
        $ok = false;
        for ($i = 0; $i < 4 && !$ok; $i++) {
            $body = httpGet('https://crt.sh/?q=' . rawurlencode($q) . '&output=json&exclude=expired', 90);
            if ($body !== null) {
                $j = json_decode($body, true);
                if (is_array($j)) {
                    foreach ($j as $row) {
                        foreach (explode("\n", (string) ($row['name_value'] ?? '')) as $n) {
                            $d = strtolower(trim($n));
                            $d = preg_replace('/^\*\./', '', $d);
                            $d = preg_replace('/^www\./', '', (string) $d);
                            if ($d && strpos($d, ' ') === false && strpos($d, '.') !== false) { $set[$d] = true; }
                        }
                    }
                    $ok = true;
                    err("  crt.sh $q -> " . count($set) . ' acum');
                }
            }
            if (!$ok) { sleep(3 * ($i + 1)); }
        }
        if (!$ok) { err("  crt.sh $q -> falló (crt.sh caído?)"); }
    }
    return array_keys($set);
}

/**
 * Dominios .ni candidatos desde el índice de Common Crawl. Detecta el índice más
 * reciente (collinfo.json) y pide todas las URLs bajo el TLD .ni, deduplicadas a
 * host. Excluye gobierno/educación/militar. Es la fuente por defecto (crt.sh cae seguido).
 */
function commonCrawlDomains(int $limit = 15000): array
{
    // 1) Índice más reciente: collinfo.json los lista del más nuevo al más viejo.
    $cdxApi = 'https://index.commoncrawl.org/CC-MAIN-2026-34-index'; // fallback
    $info = httpGet('https://index.commoncrawl.org/collinfo.json', 60);
    if ($info !== null) {
        $j = json_decode($info, true);
        if (is_array($j) && isset($j[0]['cdx-api'])) { $cdxApi = (string) $j[0]['cdx-api']; }
    }
    err('  índice Common Crawl: ' . $cdxApi);

    // 2) Todas las URLs bajo *.ni; nos quedamos con el host.
    $body = httpGet($cdxApi . '?url=*.ni&output=json&fl=url&limit=' . $limit, 150);
    if ($body === null) { err('  Common Crawl no respondió.'); return []; }

    $set = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $row  = json_decode($line, true);
        $u    = is_array($row) ? (string) ($row['url'] ?? '') : '';
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
        if ($host === '' || !str_ends_with($host, '.ni')) { continue; }
        if (preg_match('/\.(gob|gov|edu|mil)\.ni$/', $host)) { continue; } // no gobierno/edu
        $set[$host] = true;
    }
    return array_keys($set);
}

/**
 * Prueba en paralelo (curl_multi) el endpoint WooCommerce de cada dominio.
 * @return array<string,array{platform:string,count:?int}>
 */
function probe(array $domains, string $path, string $platform): array
{
    $hits = [];
    foreach (array_chunk($domains, CONCURRENCY) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $d) {
            $ch = curl_init('https://' . $d . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 12, CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => UA, CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$d] = $ch;
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 1.0); } while ($running);

        foreach ($handles as $d => $ch) {
            $resp = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            if ($resp === false || $code !== 200) { continue; }
            $headers = substr((string) $resp, 0, $hsize);
            $bodyTxt = substr((string) $resp, $hsize);
            $j = json_decode($bodyTxt, true);
            $isHit = ($platform === 'woocommerce' && is_array($j) && array_is_list($j))
                  || ($platform === 'shopify' && is_array($j) && isset($j['products']));
            if (!$isHit) { continue; }
            $count = null;
            if (preg_match('/x-wp-total:\s*(\d+)/i', $headers, $m)) { $count = (int) $m[1]; }
            $hits[$d] = ['platform' => $platform, 'count' => $count];
        }
        curl_multi_close($mh);
    }
    return $hits;
}

/** Normaliza una línea a un host limpio (tolera URLs completas, www, rutas, #comentarios). */
function normHost(string $s): ?string
{
    $s = trim($s);
    if ($s === '' || $s[0] === '#') { return null; }
    $h = preg_match('~^https?://~i', $s) ? (string) parse_url($s, PHP_URL_HOST) : (string) preg_split('~[/\s]~', $s)[0];
    $h = preg_replace('/^www\./', '', strtolower($h));
    return ($h && strpos($h, '.') !== false && strpos($h, ' ') === false) ? $h : null;
}

// --- Origen de dominios ---
$arg = $argv[1] ?? null;
if ($arg !== null && $arg !== 'crt' && is_file($arg)) {
    $domains = array_values(array_unique(array_filter(array_map('normHost', file($arg, FILE_IGNORE_NEW_LINES)))));
    err('Dominios del archivo: ' . count($domains));
} elseif ($arg === 'crt') {
    err('Bajando dominios de crt.sh…');
    $domains = crtDomains();
    err('Dominios candidatos: ' . count($domains));
} else {
    err('Bajando dominios de Common Crawl…');
    $domains = commonCrawlDomains();
    if (!$domains) { err('Common Crawl vacío; probando crt.sh…'); $domains = crtDomains(); }
    err('Dominios candidatos: ' . count($domains));
}
if (!$domains) { err('Sin dominios que probar.'); exit(1); }

// --- Probing ---
err('Probando WooCommerce…');
$woo = probe($domains, '/wp-json/wc/store/v1/products?per_page=1', 'woocommerce');
err('WooCommerce encontrados: ' . count($woo));

$rest = array_values(array_diff($domains, array_keys($woo)));
err('Probando Shopify en el resto…');
$shopify = probe($rest, '/products.json?limit=1', 'shopify');
err('Shopify encontrados: ' . count($shopify));

// --- Salida (TSV, ordenado por # de productos) ---
$all = $woo + $shopify;
uasort($all, static fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
echo "dominio\tplataforma\tproductos\n";
foreach ($all as $d => $info) {
    echo $d . "\t" . $info['platform'] . "\t" . ($info['count'] ?? '?') . "\n";
}
err('LISTO: ' . count($all) . ' tiendas e-commerce detectadas.');
