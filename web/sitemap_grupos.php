<?php
declare(strict_types=1);

/**
 * SITEMAP XML de las páginas de comparación (grupos de producto), para que
 * Google descubra e indexe /producto.php?slug=...
 * Enviar la URL de este archivo en Google Search Console.
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/xml; charset=utf-8');

$db   = Db::conn();
$base = rtrim(Verification::baseUrl(), '/');
$rows = $db->query('SELECT slug, updated_at FROM product_groups ORDER BY id')->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($rows as $r) {
    $loc = $base . '/producto.php?slug=' . rawurlencode((string) $r['slug']);
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc>'
       . '<lastmod>' . substr((string) $r['updated_at'], 0, 10) . '</lastmod>'
       . '<changefreq>daily</changefreq></url>' . "\n";
}
echo '</urlset>' . "\n";
