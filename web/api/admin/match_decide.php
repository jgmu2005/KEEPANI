<?php
declare(strict_types=1);

/**
 * POST /api/admin/match_decide.php — ADMIN. { id, decision: 'approve'|'reject' }
 * Aprobar fusiona los dos productos en un mismo product_group (union-find-lite):
 *   - ninguno agrupado → crea grupo nuevo (method 'fuzzy')
 *   - uno agrupado     → el otro se suma a ese grupo
 *   - ambos en grupos distintos → se fusionan (los miembros de uno pasan al otro)
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','à'=>'a','ñ'=>'n','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    $s = substr((string) $s, 0, 60);
    return $s !== '' ? $s : 'producto';
}

$db = Db::conn();
Auth::requireAdmin($db);

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id = (int) ($in['id'] ?? 0);
$decision = $in['decision'] ?? '';
if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    out(400, ['ok' => false, 'error' => 'Parámetros inválidos.']);
}

$st = $db->prepare("SELECT * FROM match_review WHERE id = ? AND status = 'pending'");
$st->execute([$id]);
$mr = $st->fetch();
if (!$mr) {
    out(404, ['ok' => false, 'error' => 'Candidato no encontrado o ya decidido.']);
}

if ($decision === 'reject') {
    $db->prepare("UPDATE match_review SET status='rejected', decided_at=NOW() WHERE id=?")->execute([$id]);
    out(200, ['ok' => true, 'status' => 'rejected']);
}

// --- Aprobar: fusionar ---
$aId = (int) $mr['product_a_id'];
$bId = (int) $mr['product_b_id'];

$pget = $db->prepare('SELECT id, group_id, title, brand, image_url FROM products WHERE id = ?');
$pget->execute([$aId]); $pa = $pget->fetch();
$pget->execute([$bId]); $pb = $pget->fetch();
if (!$pa || !$pb) {
    out(404, ['ok' => false, 'error' => 'Producto del par ya no existe.']);
}
$ga = $pa['group_id'] !== null ? (int) $pa['group_id'] : null;
$gb = $pb['group_id'] !== null ? (int) $pb['group_id'] : null;

try {
    $db->beginTransaction();

    if ($ga !== null && $gb !== null && $ga !== $gb) {
        $db->prepare('UPDATE products SET group_id = ? WHERE group_id = ?')->execute([$ga, $gb]);
        $db->prepare('DELETE FROM product_groups WHERE id = ?')->execute([$gb]);
        $gid = $ga;
    } elseif ($ga !== null) {
        $db->prepare('UPDATE products SET group_id = ? WHERE id = ?')->execute([$ga, $bId]);
        $gid = $ga;
    } elseif ($gb !== null) {
        $db->prepare('UPDATE products SET group_id = ? WHERE id = ?')->execute([$gb, $aId]);
        $gid = $gb;
    } else {
        $title = \OjoAlPrecio\Web\Normalizer::cleanDisplayTitle($pa['title'] ?: ($pb['title'] ?: 'Producto')) ?: 'Producto';
        $mk    = 'fuzzy:' . $aId . '-' . $bId;
        $slug  = slugify($title) . '-' . substr(sha1($mk), 0, 6);
        $db->prepare(
            'INSERT INTO product_groups (match_key, slug, canonical_title, brand, image_url, method)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$mk, $slug, $title, $pa['brand'] ?: $pb['brand'], $pa['image_url'] ?: $pb['image_url'], 'fuzzy']);
        $gid = (int) $db->lastInsertId();
        $db->prepare('UPDATE products SET group_id = ? WHERE id IN (?, ?)')->execute([$gid, $aId, $bId]);
    }

    // Recalcular agregados del grupo resultante.
    $db->prepare(
        'UPDATE product_groups g SET
            member_count = (SELECT COUNT(*) FROM products WHERE group_id = g.id AND is_active = 1),
            store_count  = (SELECT COUNT(DISTINCT store_id) FROM products WHERE group_id = g.id AND is_active = 1)
          WHERE g.id = ?'
    )->execute([$gid]);

    $db->prepare("UPDATE match_review SET status='approved', decided_at=NOW() WHERE id=?")->execute([$id]);
    $db->commit();

    out(200, ['ok' => true, 'status' => 'approved', 'group_id' => $gid]);
} catch (\Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    out(500, ['ok' => false, 'error' => 'Error al fusionar', 'detail' => $e->getMessage()]);
}
