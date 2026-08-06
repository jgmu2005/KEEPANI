<?php
declare(strict_types=1);

/**
 * HASHEO REMOTO de imágenes (dHash) — GitHub Actions.
 *
 * Baja las imágenes y calcula el dHash en el runner (rápido, sin límite de
 * tiempo de shared hosting) y manda los resultados a /api/hashes.php. Así el
 * backlog grande (decenas de miles) se resuelve en minutos, no en semanas.
 *
 * Uso:  php web/cli/hash_images_remote.php
 * Env:  OJO_INGEST_URL  (…/api/ingest.php — de ahí derivamos …/api/hashes.php)
 *       OJO_INGEST_KEY
 *       OJO_MAX_SECONDS (opcional; presupuesto, default 18000 = 5h)
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\ImageHash;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "ERROR: $s\n"); exit(1); }

$ingestUrl = getenv('OJO_INGEST_URL') ?: '';
$key       = getenv('OJO_INGEST_KEY') ?: '';
if ($ingestUrl === '' || $key === '') {
    fail('Faltan OJO_INGEST_URL y/o OJO_INGEST_KEY.');
}
$hashesUrl = getenv('OJO_HASHES_URL') ?: str_replace('ingest.php', 'hashes.php', $ingestUrl);
if (!str_contains($hashesUrl, 'hashes.php')) {
    fail('No pude derivar la URL de hashes.php desde OJO_INGEST_URL. Definí OJO_HASHES_URL.');
}
if (!function_exists('imagecreatefromstring')) {
    fail('GD no está disponible en este PHP (agregá la extensión gd).');
}

$maxSeconds = (int) (getenv('OJO_MAX_SECONDS') ?: 18000);
$start      = time();
$CONC       = 16;   // descargas concurrentes
$PAGE       = 300;  // productos por página

/** GET JSON con X-Api-Key. */
function apiGet(string $url, string $key): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $key, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $body === false) { return null; }
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : null;
}

/** POST JSON con X-Api-Key. */
function apiPost(string $url, array $payload, string $key): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $key, 'Content-Type: application/json'],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Baja varias imágenes en paralelo. @return array<int,?string> mismo orden que $urls */
function fetchMany(array $urls, int $conc): array
{
    $out = [];
    foreach (array_chunk($urls, $conc, true) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $i => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => ImageHash::UA,
                CURLOPT_ENCODING       => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) { curl_multi_select($mh, 1.0); }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out[$i] = ($body !== false && $body !== null && $body !== '' && $code >= 200 && $code < 300)
                ? (string) $body : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

$after = 0; $hashedTotal = 0; $failTotal = 0; $round = 0;

while (true) {
    if (time() - $start > $maxSeconds) { line("⏱ presupuesto de tiempo agotado, corto."); break; }

    $res = apiGet($hashesUrl . '?limit=' . $PAGE . '&after=' . $after, $key);
    if ($res === null) { fail('No pude leer pendientes de ' . $hashesUrl); }
    $items = $res['items'] ?? [];
    if (!$items) { line('✔ no quedan pendientes.'); break; }
    $round++;

    $urls = array_map(static fn(array $it): string => (string) $it['image_url'], $items);
    $bodies = fetchMany($urls, $CONC);

    $out = [];
    foreach ($items as $i => $it) {
        $b = $bodies[$i] ?? null;
        $hex = $b !== null ? ImageHash::dhashHex($b) : null;
        if ($hex !== null) { $out[] = ['id' => (int) $it['id'], 'dhash' => $hex]; $hashedTotal++; }
        else { $failTotal++; }
        $after = max($after, (int) $it['id']); // cursor: avanza aunque falle
    }

    if ($out) {
        $code = apiPost($hashesUrl, ['items' => $out], $key);
        if ($code !== 200) { fail("POST de hashes falló (HTTP $code)"); }
    }
    line("ronda $round · +" . count($out) . " · total $hashedTotal ok / $failTotal fallos · restan~" . ($res['remaining'] ?? '?'));
}

line("LISTO: $hashedTotal hasheadas, $failTotal fallos.");
