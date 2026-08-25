<?php
declare(strict_types=1);

/**
 * DIAG — por qué ciertos productos siguen agrupando como celular. Muestra los
 * valores GUARDADOS (brand_norm, model_norm) y qué devuelve PhoneModel con ellos.
 *   GET /cron/diag_phone.php?q=campana   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');
function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }

try {
    if (function_exists('opcache_invalidate')) { @opcache_invalidate(dirname(__DIR__) . '/src/PhoneModel.php', true); }

    $cfg = Db::config();
    $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $cfg['ingest_api_key'] ?? '';
    if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
        out(401, ['ok' => false, 'error' => 'No autorizado']);
    }

    $db = Db::conn();
    $q  = trim((string) ($_GET['q'] ?? 'campana'));

    $st = $db->prepare(
        "SELECT id, store_id, title, brand_norm, model_norm, group_id, group_locked, is_active
           FROM products
          WHERE model_norm LIKE ? OR title LIKE ?
          LIMIT 20"
    );
    $st->execute(['%' . $q . '%', '%' . $q . '%']);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['signature'] = \OjoAlPrecio\Web\PhoneModel::signature($r['brand_norm'], $r['model_norm']);
        $r['isPhone']   = \OjoAlPrecio\Web\PhoneModel::isPhone($r['brand_norm'], $r['model_norm']);
        $r['resolved_brand'] = \OjoAlPrecio\Web\PhoneModel::resolveBrand($r['brand_norm'], $r['model_norm']);
    }
    unset($r);

    out(200, [
        'ok'    => true,
        'q'     => $q,
        'count' => count($rows),
        'rows'  => $rows,
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
