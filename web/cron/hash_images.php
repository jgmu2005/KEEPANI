<?php
declare(strict_types=1);

/**
 * CRON de hasheo de imágenes (dHash) — para el matcher del comparador.
 * Lo dispara cron-job.org.  Ver docs/comparador-matcher.md (Fase 1, Nivel D).
 *
 *   GET/POST /cron/hash_images.php?limit=60   Header: X-Api-Key: <ingest_api_key>
 *
 * Procesa INCREMENTAL: solo productos con imagen y sin img_dhash (se hashea
 * una sola vez por producto). Baja la imagen, calcula el dHash de 64 bits
 * (redimensiona a 9x8, gris, compara píxeles contiguos) y lo guarda en hex.
 *
 * El umbral de "misma imagen" (~13-14 de Hamming) se aplica en el matcher,
 * no acá. Este job solo llena img_dhash.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ImageHash;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}
if (!function_exists('imagecreatefromstring')) {
    out(500, ['ok' => false, 'error' => 'GD no disponible en este PHP']);
}

// Default conservador para entrar bajo el timeout típico de cron-job.org (30s).
// Subilo con ?limit=N (tope 300) si ampliás el timeout del cron.
$limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 300)) : 30;

$rows = $db->query(
    'SELECT id, image_url FROM products
      WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> "" AND img_dhash IS NULL
      ORDER BY id LIMIT ' . $limit
)->fetchAll();

$upd = $db->prepare('UPDATE products SET img_dhash = ? WHERE id = ?');
$hashed = 0; $failed = 0;

foreach ($rows as $r) {
    $bytes = ImageHash::fetch((string) $r['image_url']);
    $hex = $bytes !== null ? ImageHash::dhashHex($bytes) : null;
    if ($hex !== null) {
        $upd->execute([$hex, (int) $r['id']]);
        $hashed++;
    } else {
        $failed++; // se reintenta en la próxima corrida
    }
    usleep(150000);
}

$remaining = (int) $db->query(
    'SELECT COUNT(*) FROM products
      WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> "" AND img_dhash IS NULL'
)->fetchColumn();

out(200, [
    'ok'        => true,
    'processed' => count($rows),
    'hashed'    => $hashed,
    'failed'    => $failed,
    'remaining' => $remaining,
]);
