<?php
declare(strict_types=1);

/**
 * CRON de una vez — BACKFILL de las columnas denormalizadas (Fase A).
 * Copia a products.last_* el último precio/stock de cada producto desde price_history.
 * Procesa por rangos de id, resumible y con tope de tiempo para no timeoutear.
 *
 *   GET /cron/backfill_last_price.php            Header: X-Api-Key: <ingest_api_key>
 *   GET /cron/backfill_last_price.php?from=90000 (continuar desde un id)
 *
 * Respuesta: { updated, done, next_from, remaining_active }. Si done=false, volvé a
 * llamarlo con ?from=next_from hasta que done=true.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (($cfg['ingest_api_key'] ?? '') === '' || !is_string($key) || !hash_equals((string) $cfg['ingest_api_key'], $key)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$from = max(0, (int) ($_GET['from'] ?? 0));
$step = 3000;
$maxId = (int) $db->query('SELECT MAX(id) FROM products')->fetchColumn();

$upd = $db->prepare(
    'UPDATE products p
       JOIN price_history ph ON ph.id = (SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
        SET p.last_price    = ph.price_final,
            p.last_list     = ph.list_price,
            p.last_in_stock = ph.in_stock,
            p.last_currency = ph.currency,
            p.last_date     = ph.captured_date
      WHERE p.id BETWEEN ? AND ? AND p.last_date IS NULL'
);

$updated = 0; $start = time(); $cursor = $from; $done = false;
for ($lo = $from; $lo <= $maxId; $lo += $step) {
    $hi = $lo + $step - 1;
    $upd->execute([$lo, $hi]);
    $updated += $upd->rowCount();
    $cursor = $hi + 1;
    if (time() - $start > 80) { break; }   // corta antes del límite del server
}
if ($cursor > $maxId) { $done = true; }

$remaining = (int) $db->query('SELECT COUNT(*) FROM products WHERE last_date IS NULL AND is_active = 1')->fetchColumn();

out(200, [
    'ok'               => true,
    'updated'          => $updated,
    'done'             => $done,
    'next_from'        => $done ? null : $cursor,
    'remaining_active' => $remaining,
]);
