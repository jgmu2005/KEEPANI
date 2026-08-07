<?php
declare(strict_types=1);

/**
 * SITEMAP de productos (paginado) → /precio/{id}/{slug}.
 * ?page=N (1-based, 5000 por página). Lo referencia el índice sitemap.xml.
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/xml; charset=utf-8');

$db   = Db::conn();
$base = rtrim(Verification::baseUrl(), '/');
$h    = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$slugify = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim((string) $s, '-'), 0, 70);
};

const PER_PAGE = 5000;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

// Sólo productos "maduros": rastreados en ≥2 días distintos ⇒ tienen historial
// (así el sitemap no ofrece a Google fichas finas de 1 solo punto).
$st = $db->prepare(
    'SELECT id, title, last_seen_at
       FROM products
      WHERE is_active = 1 AND title IS NOT NULL
        AND DATE(first_seen_at) < DATE(last_seen_at)
      ORDER BY id
      LIMIT ' . PER_PAGE . ' OFFSET ' . $offset
);
$st->execute();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($st->fetchAll() as $r) {
    $slug = $slugify((string) $r['title']);
    $loc  = $base . '/precio/' . (int) $r['id'] . ($slug !== '' ? '/' . $slug : '');
    $lm   = $r['last_seen_at'] ? substr((string) $r['last_seen_at'], 0, 10) : null;
    echo '  <url><loc>' . $h($loc) . '</loc>'
       . ($lm ? '<lastmod>' . $lm . '</lastmod>' : '')
       . '<changefreq>weekly</changefreq></url>' . "\n";
}
echo '</urlset>' . "\n";
