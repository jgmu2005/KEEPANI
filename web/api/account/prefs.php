<?php
declare(strict_types=1);

/**
 * /api/account/prefs.php — preferencias del perfil (requiere sesión).
 *   GET  → { tier, email, is_verified, wm_alerts, notif_cats[], categories[] }
 *   POST { wm_alerts:bool, notif_cats:[keys] } → guarda y devuelve lo mismo.
 * El filtro de categorías es GLOBAL (aplica a las notificaciones). wm_alerts
 * (liquidaciones Walmart) sólo tiene efecto para suscriptores.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\CategoryClassifier;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
$u  = Auth::requireUser($db);

function respond(PDO $db, array $u): never
{
    $st = $db->prepare('SELECT wm_alerts, notif_cats FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    $row  = $st->fetch() ?: ['wm_alerts' => 1, 'notif_cats' => ''];
    $cats = array_values(array_filter(array_map('trim', explode(',', (string) $row['notif_cats']))));
    $labels = [];
    foreach (CategoryClassifier::LABELS as $k => $label) { $labels[] = ['key' => $k, 'label' => $label]; }

    echo json_encode([
        'ok'          => true,
        'email'       => $u['email'],
        'tier'        => $u['tier'],
        'is_verified' => $u['is_verified'],
        'wm_alerts'   => (int) $row['wm_alerts'] === 1,
        'notif_cats'  => $cats,
        'categories'  => $labels,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'JSON inválido']); exit; }

    $cats = is_array($in['notif_cats'] ?? null) ? $in['notif_cats'] : [];
    // Solo claves válidas del clasificador.
    $cats = array_values(array_filter($cats, static fn($k) => is_string($k) && isset(CategoryClassifier::LABELS[$k])));
    $csv  = implode(',', array_slice($cats, 0, 30));

    // wm_alerts solo se toca si viene en el request (no lo apagamos a no-suscriptores).
    if (array_key_exists('wm_alerts', $in)) {
        $db->prepare('UPDATE users SET wm_alerts = ?, notif_cats = ? WHERE id = ?')
           ->execute([!empty($in['wm_alerts']) ? 1 : 0, $csv, $u['id']]);
    } else {
        $db->prepare('UPDATE users SET notif_cats = ? WHERE id = ?')->execute([$csv, $u['id']]);
    }
}

respond($db, $u);
