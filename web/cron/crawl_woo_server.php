<?php
declare(strict_types=1);

/**
 * CRON server-side — crawl de una tienda WooCommerce DESDE FatCow.
 *
 * Para tiendas cuyo Cloudflare bloquea las IPs de GitHub Actions (403) pero deja
 * pasar la IP del server (ej. fitshop). Corre en FatCow, baja el WooCommerce Store
 * API e ingesta directo (sin POST a /api/ingest). Lo dispara cron-job.org.
 *
 * Acepta una o varias tiendas separadas por coma:
 *   GET /cron/crawl_woo_server.php?store=fitshop
 *   GET /cron/crawl_woo_server.php?store=fetesa
 *   Header: X-Api-Key: <ingest_api_key>
 *
 * DISEÑADO PARA cron-job.org (que espera respuesta y falla si tarda): cada llamada
 * procesa un TRAMO chico (~8 páginas / ≤14s) y responde rápido, guardando un CURSOR
 * en la tabla `settings` (crawl_next_<slug>). El cron debe llamarse SEGUIDO (cada
 * ~10-30 min): el cursor avanza solo hasta completar una pasada del catálogo; al
 * terminar sella el timestamp (crawl_done_<slug>) y hace no-op hasta que pasen
 * REFRESH_HOURS (~6h), cuando arranca otra pasada. fetesa (~46 páginas) se
 * completa en ~6 llamadas. Así refresca varias veces al día, no una sola.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\WooMapper;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);
// Si cron-job.org corta la conexión por timeout (p.ej. 30s), que PHP NO aborte:
// seguimos ingiriendo por detrás hasta terminar (hasta donde el hosting lo permita).
@ignore_user_abort(true);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Acepta 1 o varias tiendas: ?store=fitshop  o  ?store=fitshop,fetesa,telcmax
// (catálogos chicos → un solo cron las baja todas). Cada tienda va aislada:
// si una falla (403/timeout), se registra el error y se sigue con las demás.
$raw   = (string) ($_GET['store'] ?? 'fitshop');
$slugs = array_values(array_filter(array_map(
    static fn($s) => preg_replace('/[^a-z0-9_-]/', '', $s),
    explode(',', $raw)
)));
if (!$slugs) { out(400, ['ok' => false, 'error' => 'Falta el parámetro store']); }

$ingest = new IngestService($db);
$results = [];
$grand   = 0;

// --- Cursor persistente en `settings` (KV). Escribimos directo (Settings::set
//     filtra por whitelist). Cada tienda guarda su próxima página + la fecha en
//     que completó la última pasada, para no re-crawlear todo el día. ---
$KV_get = static function (string $k) use ($db): ?string {
    $s = $db->prepare('SELECT v FROM settings WHERE k = ?'); $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
};
$KV_set = static function (string $k, string $v) use ($db): void {
    $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
};

// Cada request procesa un TRAMO chico y responde RÁPIDO (cron-job.org espera la
// respuesta y falla si tarda). El cron llama seguido y el cursor avanza solo
// hasta completar una pasada; después no-op hasta el día siguiente.
$PAGES_PER_CALL = 8;    // máx páginas (×100 prod) por llamada
$TIME_BUDGET    = 14;   // seg tope por request (bien bajo el corte del hosting)
$REFRESH_HOURS  = 6;    // re-crawlear si la última pasada COMPLETA fue hace más de esto
$t0             = time();

foreach ($slugs as $slug) {
    $sent = 0; $pages = 0; $error = null; $done = false; $startPage = 1; $page = 1;
    $nextKey = "crawl_next_$slug"; $doneKey = "crawl_done_$slug";
  try {
    $st = $db->prepare("SELECT base_url, currency, tax_included, tax_rate FROM stores WHERE slug = ? AND platform = 'woocommerce' AND is_active = 1");
    $st->execute([$slug]);
    $store = $st->fetch();
    if (!$store) {
        $results[] = ['store' => $slug, 'ok' => false, 'error' => 'no encontrada'];
        continue;
    }

    $startPage = max(1, (int) ($KV_get($nextKey) ?? '1'));
    $page      = $startPage;

    // ¿Completó una pasada hace menos de REFRESH_HOURS? (cursor en 1 + marca de
    // tiempo reciente) → no-op rápido. Así con un cron cada 30 min refresca cada
    // ~6h en vez de una sola vez al día.
    $doneTs = (int) ($KV_get($doneKey) ?? '0');
    if ($startPage <= 1 && $doneTs > 0 && ($t0 - $doneTs) < $REFRESH_HOURS * 3600) {
        $mins = (int) round(($t0 - $doneTs) / 60);
        $results[] = ['store' => $slug, 'ok' => true, 'skipped' => "actualizada hace {$mins} min"];
        continue;
    }

    $base        = rtrim((string) $store['base_url'], '/');
    $currency    = (string) ($store['currency'] ?? 'NIO');
    $taxIncluded = (bool) ($store['tax_included'] ?? 1);
    $taxRate     = (float) ($store['tax_rate'] ?? 0.15);
    $seen        = [];

    for ($n = 0; $n < $PAGES_PER_CALL; $n++, $page++) {
        // Corte por tiempo (siempre tras ≥1 página → hay progreso).
        if ($n > 0 && (time() - $t0) >= $TIME_BUDGET) { break; }

        $ch = curl_init($base . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => UA,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: es-NI,es;q=0.9'],
            // FatCow puede tener un CA bundle viejo → HTTP 0 por SSL.
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            $error = "Fetch falló (HTTP $code) en página $page" . ($cerr !== '' ? " · curl: $cerr" : '');
            break;
        }
        $products = json_decode((string) $body, true);
        if (!is_array($products) || count($products) === 0) { $done = true; break; } // fin del catálogo
        $pages++;

        $batch = [];
        foreach ($products as $p) {
            $h = WooMapper::handle($p);
            if ($h === '' || isset($seen[$h])) { continue; }
            $seen[$h] = true;
            $rec = WooMapper::map($p, $slug, $currency, $taxIncluded, $taxRate);
            if ($rec !== null) { $batch[] = $rec->toArray(); }
        }
        if ($batch) { $ingest->ingest($batch); $sent += count($batch); }
        if (count($products) < 100) { $done = true; break; } // última página real
        usleep(200000);
    }

    // Guardar cursor: si terminó la pasada → reinicia a 1 + sella el timestamp; si
    // no, deja apuntando a la próxima página para la siguiente llamada del cron.
    if ($error === null) {
        if ($done) { $KV_set($nextKey, '1'); $KV_set($doneKey, (string) $t0); }
        else       { $KV_set($nextKey, (string) $page); }
    }
  } catch (\Throwable $e) {
        $error = 'excepción: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
  }

    $grand += $sent;
    $row = ['store' => $slug, 'ok' => $error === null, 'from' => $startPage, 'pages' => $pages, 'sent' => $sent];
    if ($done)                       { $row['done'] = true; }
    if (!$done && $error === null)   { $row['next_from'] = $page; }
    if ($error !== null)             { $row['error'] = $error; }
    $results[] = $row;
}

$allOk = !array_filter($results, static fn($r) => !$r['ok']);
out($allOk ? 200 : 207, ['ok' => $allOk, 'total_sent' => $grand, 'stores' => $results]);
