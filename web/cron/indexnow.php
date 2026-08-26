<?php
declare(strict_types=1);

/**
 * CRON — IndexNow: notifica a Bing (y Yandex) las URLs nuevas/actualizadas para
 * que las indexe rápido. Importa porque ChatGPT/Copilot buscan vía el índice de
 * Bing: cuanto antes estén nuestras fichas y comparativos ahí, antes nos citan.
 *
 * Verificación: el archivo público /{KEY}.txt (con la KEY dentro) prueba que el
 * dominio es nuestro. La KEY de IndexNow NO es secreta (vive en un .txt público).
 *
 *   GET /cron/indexnow.php            → recientes (productos nuevos + grupos
 *                                        actualizados en los últimos ?days=3 días)
 *   GET /cron/indexnow.php?all=1      → TODOS los comparativos (siembra inicial)
 *   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

const KEY      = 'a4c48a4b2946e4fd7fc752ee6a287e2f';
const ENDPOINT = 'https://api.indexnow.org/indexnow';

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function slugify(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim((string) $s, '-'), 0, 70);
}

/** POST de un lote (≤10.000) a IndexNow. Devuelve [code, body]. */
function submit(array $urls, string $host, string $keyLoc): array {
    $payload = json_encode([
        'host' => $host, 'key' => KEY, 'keyLocation' => $keyLoc,
        'urlList' => array_values($urls),
    ], JSON_UNESCAPED_SLASHES);
    $ch = curl_init(ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}

try {
    $db  = Db::conn();
    $cfg = Db::config();
    $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $cfg['ingest_api_key'] ?? '';
    if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
        out(401, ['ok' => false, 'error' => 'No autorizado']);
    }
    try { $db->exec('SET SQL_BIG_SELECTS=1'); } catch (\Throwable $e) {}

    $base    = rtrim(Verification::baseUrl(), '/');
    $host    = (string) parse_url($base, PHP_URL_HOST);
    $keyLoc  = $base . '/' . KEY . '.txt';
    $all     = !empty($_GET['all']);
    $days    = max(1, min((int) ($_GET['days'] ?? 3), 30));

    $urls = [];

    // Comparativos (grupos): las páginas "¿dónde está más barato?" — prioridad AI.
    $gsql = $all
        ? 'SELECT slug FROM product_groups ORDER BY id'
        : 'SELECT slug FROM product_groups WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
    foreach ($db->query($gsql) as $r) {
        if (!empty($r['slug'])) { $urls[] = $base . '/producto.php?slug=' . rawurlencode((string) $r['slug']); }
    }

    // Fichas de producto NUEVAS y ya "maduras" (indexables). En modo ?all no las
    // mandamos (serían decenas de miles); para eso está el sitemap.
    if (!$all) {
        $psql = 'SELECT id, title FROM products
                  WHERE is_active = 1 AND title IS NOT NULL
                    AND DATE(first_seen_at) < DATE(last_seen_at)
                    AND first_seen_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                  LIMIT 8000';
        foreach ($db->query($psql) as $r) {
            $urls[] = $base . '/precio/' . (int) $r['id'] . '/' . slugify((string) $r['title']);
        }
    }

    $urls = array_values(array_unique($urls));
    if (!$urls) { out(200, ['ok' => true, 'submitted' => 0, 'note' => 'nada nuevo que enviar']); }

    // IndexNow acepta hasta 10.000 URLs por request.
    $results = []; $okCount = 0;
    foreach (array_chunk($urls, 10000) as $chunk) {
        [$code, $body] = submit($chunk, $host, $keyLoc);
        $ok = $code >= 200 && $code < 300;
        if ($ok) { $okCount += count($chunk); }
        $results[] = ['count' => count($chunk), 'http' => $code, 'ok' => $ok, 'body' => substr($body, 0, 120)];
    }

    out(200, [
        'ok'        => $okCount > 0,
        'mode'      => $all ? 'all-groups' : "recientes({$days}d)",
        'total'     => count($urls),
        'submitted' => $okCount,
        'batches'   => $results,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
