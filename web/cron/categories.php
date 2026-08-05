<?php
declare(strict_types=1);

/**
 * Importa todas las categorías de una tienda VTEX a la tabla `categories`.
 *
 *   GET/POST /cron/categories.php?store=sinsa   Header: X-Api-Key: <ingest_api_key>
 *
 * Un solo fetch del árbol trae todas las categorías (rápido, no necesita chunking).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\CategoryImporter;
use OjoAlPrecio\Web\Fetch\Http;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (($cfg['ingest_api_key'] ?? '') === '' || !is_string($sent) || !hash_equals((string) $cfg['ingest_api_key'], $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$slug = $_GET['store'] ?? '';
if ($slug === '') {
    out(400, ['ok' => false, 'error' => 'Falta ?store=<slug>']);
}

$st = $db->prepare('SELECT * FROM stores WHERE slug = ? AND is_active = 1');
$st->execute([$slug]);
$store = $st->fetch();
if (!$store) {
    out(404, ['ok' => false, 'error' => "Tienda no encontrada: $slug"]);
}
if ($store['platform'] !== 'vtex') {
    out(422, ['ok' => false, 'error' => "Las categorías por árbol solo aplican a tiendas VTEX (esta es {$store['platform']})"]);
}

try {
    $res = CategoryImporter::import($db, $store);
    out(200, ['ok' => true, 'store' => $slug, 'imported' => $res['imported']]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
