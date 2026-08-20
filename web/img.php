<?php
declare(strict_types=1);

/**
 * PROXY de imagen de producto, MISMO ORIGEN — para poder rasterizar la foto en
 * un <canvas> (html2canvas) sin que CORS la "contamine" al generar la tarjeta.
 *
 * NO es un proxy abierto: sólo sirve la image_url que YA tenemos en la BD, por
 * id de producto o slug de grupo. El usuario no pasa una URL arbitraria.
 *
 *   /img.php?id=123     (producto)
 *   /img.php?group=slug (grupo/comparativo)
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

$db    = Db::conn();
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$group = isset($_GET['group']) ? trim((string) $_GET['group']) : '';

$src = null;
if ($id > 0) {
    $st = $db->prepare('SELECT image_url FROM products WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $src = $st->fetchColumn() ?: null;
} elseif ($group !== '') {
    $st = $db->prepare(
        'SELECT COALESCE(NULLIF(g.image_url, ""), MAX(p.image_url)) AS img
           FROM product_groups g
           JOIN products p ON p.group_id = g.id
          WHERE g.slug = ? GROUP BY g.id LIMIT 1'
    );
    $st->execute([$group]);
    $src = $st->fetchColumn() ?: null;
}

if (!$src || !preg_match('~^https?://~i', (string) $src)) {
    http_response_code(404);
    exit;
}

// Descarga server-side. La URL viene de NUESTRA BD (confiable), no del cliente.
$ch = curl_init((string) $src);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; OjoAlPrecio/1.0)',
]);
$body = curl_exec($ch);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $code < 200 || $code >= 300 || strncmp($type, 'image/', 6) !== 0) {
    http_response_code(502);
    exit;
}

header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=86400');
echo $body;
