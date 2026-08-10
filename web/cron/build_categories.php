<?php
declare(strict_types=1);

/**
 * CRON — clasifica los productos en categorías cross-store (chips del catálogo).
 * Lo dispara cron-job.org.
 *
 *   GET/POST /cron/build_categories.php   Header: X-Api-Key: <ingest_api_key>
 *   Query:   ?only_null=1   (solo los sin clasificar; por defecto reclasifica todo)
 *            ?limit=N       (tope por corrida; batching)
 *
 * La ingesta ya asigna cat_key a cada producto nuevo (IngestService). Este cron
 * hace el BACKFILL del catálogo existente y permite RE-clasificar cuando cambian
 * las reglas de CategoryClassifier.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\CategoryClassifier;

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

$onlyNull = !empty($_GET['only_null']);
$offset   = max(0, (int) ($_GET['offset'] ?? 0));
$limit    = max(1, min((int) ($_GET['limit'] ?? 20000), 100000));

try {
    // Chequeo previo: la columna cat_key debe existir (migración 027).
    $hasCol = (bool) $db->query("SHOW COLUMNS FROM products LIKE 'cat_key'")->fetch();
    if (!$hasCol) {
        out(500, ['ok' => false, 'error' => "Falta la columna 'cat_key'. Corré la migración 027_product_cat_key.sql en phpMyAdmin."]);
    }

    // Diagnóstico: muestra títulos SIN clasificar + reparto por tienda, para
    // afinar las keywords (no escribe nada).
    if (!empty($_GET['debug'])) {
        $n = max(10, min((int) ($_GET['n'] ?? 80), 300));
        $total   = (int) $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
        $withCat = (int) $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND cat_key IS NOT NULL')->fetchColumn();
        $byStore = $db->query(
            "SELECT s.slug AS k, COUNT(*) AS n FROM products p JOIN stores s ON s.id = p.store_id
              WHERE p.is_active = 1 AND p.cat_key IS NULL GROUP BY s.slug ORDER BY n DESC"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $samples = $db->query(
            'SELECT title FROM products WHERE is_active = 1 AND cat_key IS NULL AND title IS NOT NULL
              ORDER BY id LIMIT ' . $n
        )->fetchAll(\PDO::FETCH_COLUMN);
        out(200, [
            'ok' => true, 'debug' => true,
            'total_active' => $total, 'classified' => $withCat, 'unclassified' => $total - $withCat,
            'unclassified_by_store' => $byStore,
            'samples' => $samples,
        ]);
    }

    $sql = 'SELECT id, title, brand_norm, model_norm FROM products WHERE is_active = 1'
         . ($onlyNull ? ' AND cat_key IS NULL' : '')
         . ' ORDER BY id LIMIT ' . $limit . ' OFFSET ' . $offset;
    $rows = $db->query($sql)->fetchAll();

    $upd = $db->prepare('UPDATE products SET cat_key = ?, tv_inches = ? WHERE id = ?');
    $counts = []; $classified = 0; $nulled = 0;

    foreach ($rows as $r) {
        $key = CategoryClassifier::classify($r['title'], $r['brand_norm'], $r['model_norm']);
        $tv  = $key === 'tv' ? CategoryClassifier::tvInches($r['title']) : null;
        $upd->execute([$key, $tv, (int) $r['id']]);
        if ($key === null) { $nulled++; }
        else { $classified++; $counts[$key] = ($counts[$key] ?? 0) + 1; }
    }

    arsort($counts);

    out(200, [
        'ok'          => true,
        'offset'      => $offset,
        'scanned'     => count($rows),
        'next_offset' => count($rows) === $limit ? $offset + $limit : null, // null = terminó
        'classified'  => $classified,
        'unclassified'=> $nulled,
        'by_category' => $counts,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Error interno', 'detail' => $e->getMessage()]);
}
