<?php
declare(strict_types=1);

/** POST /api/alerts/create.php — usuario. { product_id, target_price }
 *  Crea/actualiza la alerta. Respeta el tope por nivel (free 2 / donor 10).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\Alerts;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$productId = (int) ($in['product_id'] ?? 0);
$target    = (float) ($in['target_price'] ?? 0);

if ($productId <= 0 || $target <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Elegí un producto y un precio objetivo válido.']);
    exit;
}

$exists = $db->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
$exists->execute([$productId]);
if ($exists->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Producto no encontrado.']);
    exit;
}

// Si ya tiene alerta para este producto, actualiza el objetivo (no cuenta doble).
$existingId = Alerts::existingId($db, $u['id'], $productId);
if ($existingId !== null) {
    Alerts::updateTarget($db, $existingId, $target);
    echo json_encode(['ok' => true, 'updated' => true, 'id' => $existingId]);
    exit;
}

// Tope por nivel.
if (Alerts::countActive($db, $u['id']) >= $u['alert_limit']) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'limit_reached' => true,
        'error' => "Alcanzaste tu límite de {$u['alert_limit']} productos. Con una donación subís a 10.",
    ]);
    exit;
}

$id = Alerts::create($db, $u['id'], $productId, $target);
echo json_encode(['ok' => true, 'created' => true, 'id' => $id]);
