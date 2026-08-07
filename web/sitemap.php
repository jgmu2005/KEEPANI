<?php
declare(strict_types=1);

/**
 * ÍNDICE DE SITEMAPS (expuesto como /sitemap.xml vía .htaccess).
 * Referencia los sitemaps hijos: páginas estáticas, grupos de comparación y
 * los productos (paginados). Enviá /sitemap.xml a Google Search Console.
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/xml; charset=utf-8');

$db   = Db::conn();
$base = rtrim(Verification::baseUrl(), '/');
$h    = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

const PER_PAGE = 5000;
$totalProducts = (int) $db->query(
    'SELECT COUNT(*) FROM products WHERE is_active = 1 AND title IS NOT NULL'
)->fetchColumn();
$pages = max(1, (int) ceil($totalProducts / PER_PAGE));

$maps = [
    $base . '/sitemap_paginas.php',
    $base . '/sitemap_grupos.php',
];
for ($i = 1; $i <= $pages; $i++) {
    $maps[] = $base . '/sitemap_productos.php?page=' . $i;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($maps as $m) {
    echo '  <sitemap><loc>' . $h($m) . '</loc></sitemap>' . "\n";
}
echo '</sitemapindex>' . "\n";
