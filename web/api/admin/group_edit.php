<?php
declare(strict_types=1);

/**
 * /api/admin/group_edit.php — ADMIN. Edición MANUAL de un comparativo (grupo).
 *
 *   GET  ?action=search&q=texto&exclude_group=<slug>   → productos candidatos a agregar
 *   POST {action:'add',    group:<slug>, product:<id>, merge:<bool>}
 *   POST {action:'remove', group:<slug>, product:<id>}
 *
 * Al agregar/quitar se marca products.group_locked = 1 para que los crons de
 * agrupación automática NO reviertan la decisión manual. 'merge' trae TODOS los
 * miembros del grupo al que pertenece el producto agregado.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;
use OjoAlPrecio\Web\SearchQuery;

header('Content-Type: application/json; charset=utf-8');

$db = Db::conn();
Auth::requireAdmin($db);

function out(int $s, array $p): never
{
    http_response_code($s);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function groupIdBySlug(PDO $db, string $slug): ?int
{
    $st = $db->prepare('SELECT id FROM product_groups WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','à'=>'a','è'=>'e','ñ'=>'n','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    $s = substr((string) $s, 0, 60);
    return $s !== '' ? $s : 'comparativo';
}

/** Recalcula member_count / store_count de un grupo (solo activos). */
function recount(PDO $db, int $gid): void
{
    $db->prepare(
        'UPDATE product_groups g SET
            member_count = (SELECT COUNT(*) FROM products WHERE group_id = g.id AND is_active = 1),
            store_count  = (SELECT COUNT(DISTINCT store_id) FROM products WHERE group_id = g.id AND is_active = 1)
          WHERE g.id = ?'
    )->execute([$gid]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

// ---- Buscar candidatos a agregar (lectura) ----
if ($method === 'GET') {
    $q           = trim((string) ($_GET['q'] ?? ''));
    $excludeSlug = trim((string) ($_GET['exclude_group'] ?? ''));
    if (mb_strlen($q) < 2) {
        out(200, ['ok' => true, 'items' => []]);
    }
    $excludeId = $excludeSlug !== '' ? groupIdBySlug($db, $excludeSlug) : null;

    // Palabras sueltas en cualquier orden: "cubitt audifono" matchea "Audífono Cubitt…".
    [$qCond, $qParams] = SearchQuery::like($q, ['p.title']);
    if ($qCond === '') { out(200, ['ok' => true, 'items' => []]); }

    $sql = 'SELECT p.id, p.title, p.image_url, s.name AS store_name,
                   p.last_price AS price_final, p.last_currency AS currency,
                   g.slug AS group_slug, g.canonical_title AS group_title, g.member_count AS group_members
              FROM products p
              JOIN stores s ON s.id = p.store_id
         LEFT JOIN product_groups g ON g.id = p.group_id
             WHERE p.is_active = 1 AND ' . $qCond
             . ($excludeId ? ' AND (p.group_id IS NULL OR p.group_id <> :ex)' : '')
             . ' ORDER BY p.title LIMIT 25';
    $params = $qParams;
    if ($excludeId) { $params[':ex'] = $excludeId; }
    $st = $db->prepare($sql);
    $st->execute($params);

    $items = array_map(static fn($r) => [
        'id'            => (int) $r['id'],
        'title'         => $r['title'],
        'image'         => $r['image_url'],
        'store'         => $r['store_name'],
        'price'         => $r['price_final'] !== null ? (float) $r['price_final'] : null,
        'currency'      => $r['currency'] ?? 'NIO',
        'in_group'      => $r['group_slug'],
        'in_group_title'=> $r['group_title'],
        'group_members' => $r['group_members'] !== null ? (int) $r['group_members'] : 0,
    ], $st->fetchAll());
    out(200, ['ok' => true, 'items' => $items]);
}

// ---- Mutaciones ----
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) { $body = $_POST; }

$action = (string) ($body['action'] ?? '');

// CREAR un comparativo nuevo desde cero (no necesita grupo previo).
if ($action === 'create') {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) ($body['products'] ?? [])),
        static fn($v) => $v > 0
    )));
    if (count($ids) < 2) {
        out(400, ['ok' => false, 'error' => 'Elegí al menos 2 productos para comparar.']);
    }
    $in = implode(',', $ids); // ints saneados
    // Representante: título más largo (más descriptivo), primera imagen/marca.
    $rep = $db->query("SELECT title, brand, image_url FROM products
                        WHERE id IN ($in) ORDER BY CHAR_LENGTH(title) DESC LIMIT 1")->fetch();
    $title = $rep['title'] ?: 'Comparativo manual';
    $mk    = 'manual:' . bin2hex(random_bytes(8));
    $slug  = slugify($title) . '-' . substr(sha1($mk), 0, 6);

    $db->prepare(
        'INSERT INTO product_groups (match_key, slug, canonical_title, brand, image_url, member_count, store_count, method)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?)'
    )->execute([$mk, $slug, $title, $rep['brand'] ?: null, $rep['image_url'] ?: null, 'manual']);
    $newGid = (int) $db->lastInsertId();

    // Grupos viejos de esos productos (para recontar tras moverlos).
    $oldGids = $db->query("SELECT DISTINCT group_id FROM products WHERE id IN ($in) AND group_id IS NOT NULL")
                  ->fetchAll(PDO::FETCH_COLUMN);

    $db->exec("UPDATE products SET group_id = $newGid, group_locked = 1 WHERE id IN ($in)");
    foreach ($oldGids as $og) { recount($db, (int) $og); }
    recount($db, $newGid);

    out(200, ['ok' => true, 'slug' => $slug, 'url' => 'producto.php?slug=' . rawurlencode($slug)]);
}

$slug = trim((string) ($body['group'] ?? ''));
$pid  = (int) ($body['product'] ?? 0);

$gid = $slug !== '' ? groupIdBySlug($db, $slug) : null;
if ($gid === null) { out(404, ['ok' => false, 'error' => 'Comparativo no encontrado']); }
if ($pid <= 0)     { out(400, ['ok' => false, 'error' => 'Falta el producto']); }

if ($action === 'add') {
    $st = $db->prepare('SELECT group_id FROM products WHERE id = ? AND is_active = 1');
    $st->execute([$pid]);
    $cur = $st->fetchColumn();
    if ($cur === false) { out(404, ['ok' => false, 'error' => 'Producto no encontrado']); }
    $curGid = $cur !== null ? (int) $cur : null;

    if ($curGid === $gid) { out(200, ['ok' => true, 'moved' => 0, 'note' => 'ya estaba']); }

    $merge = !empty($body['merge']);
    if ($curGid !== null && $merge) {
        // Traer TODOS los miembros del grupo origen (unir comparativos).
        $upd = $db->prepare('UPDATE products SET group_id = ?, group_locked = 1 WHERE group_id = ? AND is_active = 1');
        $upd->execute([$gid, $curGid]);
        $moved = $upd->rowCount();
    } else {
        $db->prepare('UPDATE products SET group_id = ?, group_locked = 1 WHERE id = ?')->execute([$gid, $pid]);
        $moved = 1;
    }
    if ($curGid !== null) { recount($db, $curGid); }
    recount($db, $gid);
    out(200, ['ok' => true, 'moved' => $moved]);
}

if ($action === 'remove') {
    $upd = $db->prepare('UPDATE products SET group_id = NULL, group_locked = 1 WHERE id = ? AND group_id = ?');
    $upd->execute([$pid, $gid]);
    recount($db, $gid);
    out(200, ['ok' => true, 'removed' => $upd->rowCount()]);
}

if ($action === 'unlock') {
    // Devolver el producto al modo AUTOMÁTICO: se quita el candado para que los
    // crons de agrupación vuelvan a manejarlo (lo reagrupan por SKU/EAN/modelo).
    $db->prepare('UPDATE products SET group_locked = 0 WHERE id = ?')->execute([$pid]);
    out(200, ['ok' => true, 'unlocked' => 1]);
}

out(400, ['ok' => false, 'error' => 'Acción inválida']);

} catch (\Throwable $e) {
    // El error más común: falta la migración 036 (columna group_locked).
    $hint = str_contains($e->getMessage(), 'group_locked')
        ? ' — falta correr la migración 036_group_locked.sql'
        : '';
    out(500, ['ok' => false, 'error' => $e->getMessage() . $hint]);
}
