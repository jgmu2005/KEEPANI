<?php
declare(strict_types=1);

/**
 * /api/admin/mk_stores.php — ADMIN. Gestión de tiendas del marketplace (Treinta).
 *   GET                              → lista tiendas
 *   POST {action:'add', url}         → agrega por URL
 *   POST {action:'toggle', id, on}   → activa/desactiva
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Marketplace\MarketplaceRepo;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);
$repo = new MarketplaceRepo($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $action = $in['action'] ?? '';
    if ($action === 'add') {
        $res = $repo->addStore((string) ($in['url'] ?? ''));
        echo json_encode($res + ['stores' => $repo->allStores()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'toggle') {
        $repo->setStoreActive((int) ($in['id'] ?? 0), !empty($in['on']));
        echo json_encode(['ok' => true, 'stores' => $repo->allStores()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción inválida']);
    exit;
}

echo json_encode(['ok' => true, 'stores' => $repo->allStores()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
