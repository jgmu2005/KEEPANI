<?php
declare(strict_types=1);

/** GET /api/admin/stats.php — ADMIN. Resumen de métricas del sitio. */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

/** Escalar seguro (null si la tabla/columna no existe todavía). */
$one = static function (string $sql, array $p = []) use ($db) {
    try { $st = $db->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
    catch (\Throwable $e) { return null; }
};
/** Filas seguras. */
$rows = static function (string $sql) use ($db): array {
    try { return $db->query($sql)->fetchAll(); } catch (\Throwable $e) { return []; }
};

// Usuarios
$byTier = [];
foreach ($rows("SELECT tier, COUNT(*) n FROM users GROUP BY tier") as $r) { $byTier[$r['tier']] = (int) $r['n']; }

// Catálogo por tienda
$byStore = $rows(
    "SELECT s.name, COUNT(*) n
       FROM products p JOIN stores s ON s.id = p.store_id
      WHERE p.is_active = 1 GROUP BY s.id ORDER BY n DESC"
);

// Puntos de precio: estimado rápido (evita COUNT(*) sobre millones de filas).
$pricePoints = $one("SELECT table_rows FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'price_history'");

// Vistas (web vs extensión), últimos 30 días
$hits30 = [];
foreach ($rows("SELECT source, SUM(hits) n FROM product_hits WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY source") as $r) {
    $hits30[$r['source']] = (int) $r['n'];
}
// Top productos vistos (30 días)
$topViewed = $rows(
    "SELECT p.title, s.name AS store, SUM(h.hits) AS hits
       FROM product_hits h JOIN products p ON p.id = h.product_id JOIN stores s ON s.id = p.store_id
      WHERE h.day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY h.product_id ORDER BY hits DESC LIMIT 10"
);

echo json_encode([
    'ok' => true,
    'users' => [
        'total'       => (int) ($one("SELECT COUNT(*) FROM users") ?? 0),
        'free'        => $byTier['free'] ?? 0,
        'onetime'     => $byTier['onetime'] ?? 0,
        'subscriber'  => $byTier['subscriber'] ?? 0,
        'verified'    => (int) ($one("SELECT COUNT(*) FROM users WHERE is_verified = 1") ?? 0),
        'new_7d'      => (int) ($one("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)") ?? 0),
    ],
    'alerts' => [
        'active'      => (int) ($one("SELECT COUNT(*) FROM alerts WHERE is_active = 1") ?? 0),
        'notif_total' => (int) ($one("SELECT COUNT(*) FROM notifications") ?? 0),
        'notif_7d'    => (int) ($one("SELECT COUNT(*) FROM notifications WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)") ?? 0),
    ],
    'donations' => [
        'total'       => (int) ($one("SELECT COUNT(*) FROM donations") ?? 0),
        'd7'          => (int) ($one("SELECT COUNT(*) FROM donations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)") ?? 0),
    ],
    'catalog' => [
        'products'    => (int) ($one("SELECT COUNT(*) FROM products WHERE is_active = 1") ?? 0),
        'price_points'=> $pricePoints !== null ? (int) $pricePoints : null,
        'by_store'    => array_map(static fn($r) => ['name' => $r['name'], 'n' => (int) $r['n']], $byStore),
        'groups'      => (int) ($one("SELECT COUNT(*) FROM product_groups WHERE store_count >= 2") ?? 0),
        'matches_pending' => (int) ($one("SELECT COUNT(*) FROM match_review WHERE status = 'pending'") ?? 0),
    ],
    'walmart' => [
        'products'    => (int) ($one("SELECT COUNT(*) FROM wm_products") ?? 0),
        'drops'       => (int) ($one("SELECT COUNT(*) FROM wm_drops") ?? 0),
        'drops_7d'    => (int) ($one("SELECT COUNT(*) FROM wm_drops WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)") ?? 0),
    ],
    'usage' => [
        'views_web_30d' => $hits30['web'] ?? 0,
        'views_ext_30d' => $hits30['ext'] ?? 0,
        'top_viewed'    => array_map(static fn($r) => ['title' => $r['title'], 'store' => $r['store'], 'hits' => (int) $r['hits']], $topViewed),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
