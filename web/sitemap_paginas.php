<?php
declare(strict_types=1);

/** SITEMAP de páginas estáticas (home, ayuda, términos, marketplace, celulares). */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Verification;

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(Verification::baseUrl(), '/');
$h    = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$urls = [
    ['loc' => $base . '/',                   'freq' => 'daily',   'pri' => '1.0'],
    ['loc' => $base . '/compara-telefonos',  'freq' => 'daily',   'pri' => '0.9'],
    ['loc' => $base . '/marketplace',        'freq' => 'daily',   'pri' => '0.8'],
    ['loc' => $base . '/liquidaciones',      'freq' => 'daily',   'pri' => '0.8'],
    ['loc' => $base . '/ayuda.html',         'freq' => 'monthly', 'pri' => '0.4'],
    ['loc' => $base . '/terminos.html',      'freq' => 'yearly',  'pri' => '0.2'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . $h($u['loc']) . '</loc>'
       . '<changefreq>' . $u['freq'] . '</changefreq>'
       . '<priority>' . $u['pri'] . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
