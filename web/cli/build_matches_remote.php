<?php
declare(strict_types=1);

/**
 * MATCHER REMOTO (GitHub Actions, slice 2b). El scoring O(n²) por marca corre
 * acá (rápido, sin límite de shared hosting); FatCow solo sirve los bloques y
 * recibe los candidatos.
 *
 * Uso:  php web/cli/build_matches_remote.php
 * Env:  OJO_INGEST_URL (…/api/ingest.php → derivamos …/api/match_data.php)
 *       OJO_INGEST_KEY
 *       OJO_MAX_SECONDS (opcional, default 18000 = 5h)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Matcher;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$key       = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $key === '') { fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.'); }
$dataUrl = getenv('OJO_MATCH_URL') ?: str_replace('ingest.php', 'match_data.php', $ingestUrl);
if (!str_contains($dataUrl, 'match_data.php')) { fail('No pude derivar match_data.php; definí OJO_MATCH_URL.'); }

$maxSeconds = (int) (getenv('OJO_MAX_SECONDS') ?: 18000);
$start = time();

function apiGet(string $url, string $key): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $key, 'Accept: application/json']]);
    $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($code !== 200 || $body === false) {
        fwrite(STDERR, "  [apiGet] HTTP $code" . ($cerr ? " curl:$cerr" : '') . " body:" . substr((string) $body, 0, 400) . "\n");
        return null;
    }
    $j = json_decode((string) $body, true);
    if (!is_array($j)) { fwrite(STDERR, "  [apiGet] respuesta no-JSON: " . substr((string) $body, 0, 400) . "\n"); }
    return is_array($j) ? $j : null;
}
function apiPost(string $url, array $payload, string $key): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $key, 'Content-Type: application/json']]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}

$after = ''; $round = 0; $cand = 0; $brands = 0;

while (true) {
    if (time() - $start > $maxSeconds) { line('⏱ presupuesto agotado, corto.'); break; }

    $res = apiGet($dataUrl . '?limit=25&after=' . rawurlencode($after), $key);
    if ($res === null) { fail('No pude leer bloques de ' . $dataUrl); }
    $blocks = $res['blocks'] ?? [];
    if (!$blocks) { line('✔ no quedan marcas por procesar.'); break; }
    $round++;

    $items = [];
    foreach ($blocks as $blk) {
        $prods = $blk['products'] ?? [];
        $n = count($prods);
        $brands++;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $A = $prods[$i]; $B = $prods[$j];
                if ($A['store_id'] === $B['store_id']) { continue; }
                if ($A['group_id'] !== null && $A['group_id'] === $B['group_id']) { continue; }
                $r = Matcher::score($A, $B);
                if (!$r['ok']) { continue; }
                $items[] = ['a' => $A['id'], 'b' => $B['id'], 'score' => $r['score'],
                            'img' => $r['img'], 'jac' => $r['jac'], 'method' => $r['method']];
            }
        }
    }

    if ($items) {
        // enviar por lotes de 500
        foreach (array_chunk($items, 500) as $chunk) {
            $code = apiPost($dataUrl, ['items' => $chunk], $key);
            if ($code !== 200) { fail("POST de candidatos falló (HTTP $code)"); }
            $cand += count($chunk);
        }
    }
    $after = (string) ($res['next'] ?? $after);
    line("ronda $round · $brands marcas · +" . count($items) . " candidatos (acum $cand)");
    if (!empty($res['done'])) { line('✔ última página.'); break; }
}

line("LISTO: $brands marcas procesadas, $cand candidatos enviados.");
