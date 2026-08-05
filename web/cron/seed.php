<?php
declare(strict_types=1);

/**
 * SEED MASIVO (crawler VTEX) — poblar el catálogo "poco a poco".
 *
 *   GET/POST /cron/seed.php?store=sinsa
 *   Header:  X-Api-Key: <ingest_api_key>
 *   Query:   ?store=sinsa   (requerido; tienda VTEX)
 *            ?pages=5       (opcional; páginas de ~50 productos por llamada)
 *            ?reset=1       (opcional; reinicia el cursor desde 0)
 *
 * Cada llamada procesa unas pocas páginas desde donde quedó (cursor en
 * crawl_cursors) e ingiere ~50 productos por página. Llamalo varias veces
 * (o con un cron cada pocos minutos) hasta que responda done:true.
 * Así ninguna corrida es pesada y no se topa con el límite de tiempo de PHP.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\VtexCatalogCrawler;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Auth ---
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$slug  = $_GET['store'] ?? '';
$pages = max(1, min((int) ($_GET['pages'] ?? 5), 20));
$reset = !empty($_GET['reset']);

if ($slug === '') {
    out(400, ['ok' => false, 'error' => 'Falta ?store=<slug> (tienda VTEX)']);
}

try {
    $db = Db::conn();

    // Tienda y validación de plataforma.
    $st = $db->prepare('SELECT * FROM stores WHERE slug = ? AND is_active = 1');
    $st->execute([$slug]);
    $store = $st->fetch();
    if (!$store) {
        out(404, ['ok' => false, 'error' => "Tienda no encontrada: $slug"]);
    }
    if ($store['platform'] !== 'vtex') {
        out(422, ['ok' => false, 'error' => "El seed solo soporta tiendas VTEX por ahora (esta es {$store['platform']})"]);
    }

    // Cursor (crea la fila si no existe).
    $db->prepare('INSERT IGNORE INTO crawl_cursors (store_slug) VALUES (?)')->execute([$slug]);
    if ($reset) {
        $db->prepare('UPDATE crawl_cursors SET next_from = 0, total_seen = 0, done = 0 WHERE store_slug = ?')
           ->execute([$slug]);
    }
    $cur = $db->prepare('SELECT next_from, total_seen, done FROM crawl_cursors WHERE store_slug = ?');
    $cur->execute([$slug]);
    $cursor = $cur->fetch();

    if ((int) $cursor['done'] === 1) {
        out(200, ['ok' => true, 'store' => $slug, 'done' => true,
                  'total_seen' => (int) $cursor['total_seen'],
                  'note' => 'Ya se recorrió el catálogo. Usá ?reset=1 para volver a empezar.']);
    }

    $crawler = VtexCatalogCrawler::fromStore($store, new Http());
    $ingest  = new IngestService($db);

    $from       = (int) $cursor['next_from'];
    $ingested   = 0;
    $done       = false;
    $fetchError = false;

    for ($i = 0; $i < $pages; $i++) {
        if ($from > VtexCatalogCrawler::MAX_OFFSET) { $done = true; break; }

        $recs = $crawler->page($from);
        if ($recs === null) { $fetchError = true; break; }  // fallo: NO marcar done, reintentar luego
        if ($recs === [])   { $done = true; break; }         // fin real del catálogo

        $ingest->ingest($recs);
        $ingested += count($recs);
        $from     += VtexCatalogCrawler::PAGE_SIZE;
    }

    // Guardar avance (si hubo fallo de fetch, el cursor NO avanza en esa página).
    $db->prepare(
        'UPDATE crawl_cursors SET next_from = ?, total_seen = total_seen + ?, done = ? WHERE store_slug = ?'
    )->execute([$from, $ingested, $done ? 1 : 0, $slug]);

    $total = (int) $cursor['total_seen'] + $ingested;

    out(200, [
        'ok'                => true,
        'store'             => $slug,
        'ingested_this_call'=> $ingested,
        'total_seen'        => $total,
        'next_from'         => $from,
        'done'              => $done,
        'fetch_error'       => $fetchError,
        'note'              => $fetchError
            ? 'La tienda falló en esta página (transitorio). Volvé a llamar para reintentar desde el mismo punto.'
            : ($done ? 'Catálogo recorrido ✔' : 'Volvé a llamar para seguir avanzando.'),
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
