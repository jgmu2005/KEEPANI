<?php
declare(strict_types=1);

/**
 * GET /api/track.php — PÚBLICO. "Rastrear este producto".
 *
 * Si el producto ya está en la BD, devuelve su historial (igual que history.php).
 * Si NO, lo lee EN VIVO de la tienda, lo guarda (crea product + primer punto de
 * histórico) y lo devuelve. Así el catálogo crece bajo demanda al pegar una URL.
 *
 *   ?url=https://www.sinsa.com.ni/...-140513297/p
 *   ?store=sinsa&sku=140513297
 *
 * Solo acepta URLs de tiendas reconocidas (VTEX / Copasa); si no, responde 404.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\IngestService;
use OjoAlPrecio\Web\Fetch\Http;
use OjoAlPrecio\Web\Fetch\AdapterFactory;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $db    = Db::conn();
    $repo  = new ProductRepository($db);

    $store = $_GET['store'] ?? null;
    $sku   = $_GET['sku']   ?? null;
    $url   = $_GET['url']   ?? null;

    $added = false;
    $id = $repo->resolve(null, $store, $sku, $url);

    if ($id === null) {
        // No está rastreado: intentar leerlo en vivo y agregarlo.
        $loc = $repo->locate($store, $sku, $url);
        if (!$loc) {
            out(404, ['ok' => false, 'error' => 'No pudimos leer ese enlace. Pegá el link de un PRODUCTO (no de una búsqueda o categoría). Rastreamos más de 20 tiendas de Nicaragua; ver la lista en ojoalprecio.online/ayuda.html#tiendas']);
        }
        $storeRow = $repo->storeBySlug($loc['slug']);
        if (!$storeRow) {
            out(404, ['ok' => false, 'error' => 'Tienda no soportada todavía.']);
        }

        try {
            $adapter = AdapterFactory::fromStore($storeRow, new Http());
            $rec = ($url !== null && $url !== '')
                ? $adapter->fetchByUrl((string) $url, $loc['sku'])
                : $adapter->fetchBySku($loc['sku']);
        } catch (\Throwable $e) {
            out(502, ['ok' => false, 'error' => 'No se pudo leer la tienda', 'detail' => $e->getMessage()]);
        }

        if ($rec === null) {
            out(404, ['ok' => false, 'error' => 'No encontramos ese producto en la tienda (¿URL correcta?).']);
        }

        (new IngestService($db))->ingest([$rec->toArray()]);
        // Resolver por el SKU CANÓNICO que devolvió el adaptador (VTEX pudo
        // recibir una referencia y normalizarla al productId real).
        $id = $repo->resolve(null, $rec->storeSlug, $rec->sku, null);
        if ($id === null) {
            out(500, ['ok' => false, 'error' => 'No se pudo guardar el producto.']);
        }
        $added = true;
    }

    $history = $repo->history($id);
    out(200, [
        'ok'      => true,
        'added'   => $added,
        'product' => $repo->product($id),
        'stats'   => $repo->stats($history),
        'history' => $history,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
