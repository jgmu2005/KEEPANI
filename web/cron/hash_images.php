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

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

/** Baja una imagen (bytes) o null. */
function fetchImage(string $url, string $ua): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300 && $body !== '') ? (string) $body : null;
}

/** dHash de 64 bits en hex (16 chars), o null si la imagen no se pudo decodificar. */
function dhashHex(string $bytes): ?string
{
    $img = @imagecreatefromstring($bytes);
    if (!$img) {
        return null;
    }
    $w = 9; $h = 8;
    $small = imagecreatetruecolor($w, $h);
    // Fondo blanco (por si la imagen trae transparencia).
    $white = imagecolorallocate($small, 255, 255, 255);
    imagefilledrectangle($small, 0, 0, $w, $h, $white);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
    imagedestroy($img);

    $gray = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
            $gray[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        }
    }
    imagedestroy($small);

    $bits = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $bits .= $gray[$y][$x] > $gray[$y][$x + 1] ? '1' : '0';
        }
    }
    $hex = '';
    for ($i = 0; $i < 64; $i += 4) {
        $hex .= dechex((int) bindec(substr($bits, $i, 4)));
    }
    return $hex; // 16 chars
}

$rows = $db->query(
    'SELECT id, image_url FROM products
      WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> "" AND img_dhash IS NULL
      ORDER BY id LIMIT ' . $limit
)->fetchAll();

$upd = $db->prepare('UPDATE products SET img_dhash = ? WHERE id = ?');
$hashed = 0; $failed = 0;

foreach ($rows as $r) {
    $bytes = fetchImage((string) $r['image_url'], $UA);
    $hex = $bytes !== null ? dhashHex($bytes) : null;
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
