<?php
declare(strict_types=1);

/**
 * CRON — matcher difuso del comparador (slice 2b): genera CANDIDATOS a la cola
 * de revisión (match_review). NO fusiona automáticamente; el admin decide.
 * Lo dispara cron-job.org.  Ver docs/comparador-matcher.md.
 *
 *   GET/POST /cron/build_matches.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Bloquea por MARCA (solo marcas presentes en ≥2 tiendas), compara pares
 * cross-store con Matcher (imagen dHash + título + atributos + subtipo) y
 * encola los que pasan el umbral. Requiere brand_norm/model_norm poblados y,
 * idealmente, img_dhash (job de imágenes).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Matcher;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

const MAX_INSERT       = 5000;   // candidatos por corrida (idempotente: reanuda)
const MAX_PAIRS_BRAND  = 40000;  // tope de comparaciones por marca (anti-patológico)

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Marcas presentes en ≥2 tiendas (las únicas donde puede haber cruce).
$brands = $db->query(
    "SELECT brand_norm FROM products
      WHERE is_active = 1 AND brand_norm IS NOT NULL AND brand_norm <> '' AND model_norm IS NOT NULL
      GROUP BY brand_norm HAVING COUNT(DISTINCT store_id) >= 2"
)->fetchAll(\PDO::FETCH_COLUMN);

$loadBrand = $db->prepare(
    'SELECT p.id, p.store_id, p.model_norm, p.img_dhash, p.group_id,
            ph.price_final AS price
       FROM products p
       LEFT JOIN price_history ph ON ph.id = (
            SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
      WHERE p.is_active = 1 AND p.brand_norm = ? AND p.model_norm IS NOT NULL'
);
$ins = $db->prepare(
    'INSERT IGNORE INTO match_review (product_a_id, product_b_id, score, img_distance, jaccard, method)
     VALUES (?, ?, ?, ?, ?, ?)'
);

$inserted = 0; $scored = 0; $brandsDone = 0; $capped = false;

foreach ($brands as $brand) {
    if ($inserted >= MAX_INSERT) { $capped = true; break; }

    $loadBrand->execute([$brand]);
    $prods = $loadBrand->fetchAll();
    $n = count($prods);
    if ($n < 2) { continue; }
    // normaliza tipos
    foreach ($prods as &$p) {
        $p['price']    = $p['price'] !== null ? (float) $p['price'] : null;
        $p['group_id'] = $p['group_id'] !== null ? (int) $p['group_id'] : null;
        $p['store_id'] = (int) $p['store_id'];
    }
    unset($p);

    $pairs = 0;
    for ($i = 0; $i < $n && $inserted < MAX_INSERT; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $A = $prods[$i]; $B = $prods[$j];
            if ($A['store_id'] === $B['store_id']) { continue; }             // solo cross-store
            if ($A['group_id'] !== null && $A['group_id'] === $B['group_id']) { continue; } // ya juntos
            if (++$pairs > MAX_PAIRS_BRAND) { break 2; }
            $scored++;

            $r = Matcher::score($A, $B);
            if (!$r['ok']) { continue; }

            $a = min((int) $A['id'], (int) $B['id']);
            $b = max((int) $A['id'], (int) $B['id']);
            $ins->execute([$a, $b, $r['score'], $r['img'], $r['jac'], $r['method']]);
            $inserted += $ins->rowCount() > 0 ? 1 : 0; // rowCount 0 si ya existía (INSERT IGNORE)
        }
    }
    $brandsDone++;
}

out(200, [
    'ok'            => true,
    'brands_multi'  => count($brands),
    'brands_done'   => $brandsDone,
    'pairs_scored'  => $scored,
    'candidates_new'=> $inserted,
    'capped'        => $capped,
    'pending_total' => (int) $db->query("SELECT COUNT(*) FROM match_review WHERE status = 'pending'")->fetchColumn(),
]);
