<?php
declare(strict_types=1);

/**
 * CRON — agrupamiento de CELULARES por modelo (comparador).
 * Lo dispara cron-job.org.  Ver docs/comparador-matcher.md.
 *
 *   GET/POST /cron/build_phone_groups.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Los celulares no matchean bien por imagen (fondo blanco); acá se agrupan por
 * FIRMA DE MODELO (marca|modelo) entre tiendas — como Prexia. Corre DESPUÉS de
 * build_groups (reasigna celulares a su grupo de modelo, que suele cubrir más
 * tiendas que el match exacto). Al final recalcula y limpia grupos vacíos.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\PhoneModel;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return substr($s, 0, 60) ?: 'celular';
}

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Candidatos a celular: marca conocida en brand_norm, O una marca/submarca en el
// TÍTULO (para tiendas que NO exponen marca, ej. telcmax → brand_norm NULL).
// isPhone()/signature() filtran con precisión en PHP (TVs, accesorios, etc.).
$rows = $db->query(
    "SELECT id, store_id, brand, brand_norm, model_norm, title, image_url
       FROM products
      WHERE is_active = 1 AND model_norm IS NOT NULL AND group_locked = 0
        AND (
             brand_norm IN ('apple','samsung','xiaomi','honor','huawei','motorola',
                            'tecno','infinix','oppo','realme','zte','nokia','itel','alcatel','google')
             OR CONCAT(' ', model_norm, ' ') REGEXP
                ' (apple|samsung|xiaomi|honor|huawei|motorola|tecno|infinix|oppo|realme|zte|nokia|itel|alcatel|google|iphone|galaxy|redmi|poco|moto|pixel|magic|reno|narzo|camon|spark|pova|mate|nova) '
        )"
)->fetchAll();

$clusters = []; // firma => [filas]
foreach ($rows as $r) {
    $sig = PhoneModel::signature($r['brand_norm'], $r['model_norm']);
    if ($sig !== null) {
        $clusters[$sig][] = $r;
    }
}

// --- Diagnóstico: ?debug=1 muestra por qué no se forman clusters cruzados ---
if (!empty($_GET['debug'])) {
    $names = [];
    foreach ($db->query('SELECT id, slug FROM stores') as $s) { $names[(int) $s['id']] = $s['slug']; }

    $byStore = [];                       // tienda => # productos-teléfono (con firma)
    foreach ($clusters as $items) {
        foreach ($items as $p) { $byStore[$names[(int) $p['store_id']] ?? '?'] = ($byStore[$names[(int) $p['store_id']] ?? '?'] ?? 0) + 1; }
    }
    arsort($byStore);

    $sigInfo = [];
    foreach ($clusters as $sig => $items) {
        $st = array_values(array_unique(array_map(static fn(array $p): string => $names[(int) $p['store_id']] ?? '?', $items)));
        $sigInfo[] = ['sig' => $sig, 'n' => count($items), 'stores' => $st, 'store_count' => count($st)];
    }
    usort($sigInfo, static fn($a, $b) => $b['store_count'] <=> $a['store_count'] ?: $b['n'] <=> $a['n']);

    out(200, [
        'ok'                 => true,
        'debug'              => true,
        'phone_products'     => count($rows),
        'with_signature'     => array_sum(array_map('count', $clusters)),
        'distinct_sigs'      => count($clusters),
        'by_store'           => $byStore,
        'multi_store_sigs'   => count(array_filter($sigInfo, static fn($x) => $x['store_count'] >= 2)),
        'top_signatures'     => array_slice($sigInfo, 0, 40),
    ]);
}

$upsert = $db->prepare(
    'INSERT INTO product_groups (match_key, slug, canonical_title, brand, image_url, member_count, store_count, method)
     VALUES (:mk, :slug, :title, :brand, :img, :n, :stores, :method)
     ON DUPLICATE KEY UPDATE
        slug = VALUES(slug), canonical_title = VALUES(canonical_title), brand = VALUES(brand),
        image_url = VALUES(image_url), member_count = VALUES(member_count),
        store_count = VALUES(store_count), method = VALUES(method)'
);
$getId = $db->prepare('SELECT id FROM product_groups WHERE match_key = ?');

$groups = 0; $members = 0;
foreach ($clusters as $sig => $items) {
    $stores = array_unique(array_map(static fn(array $p): int => (int) $p['store_id'], $items));
    if (count($stores) < 2) { continue; } // debe cruzar tiendas

    // Representante: título más largo (más descriptivo); primera imagen no vacía.
    usort($items, static fn(array $a, array $b): int => strlen((string) $b['title']) <=> strlen((string) $a['title']));
    $title = (string) ($items[0]['title'] ?? 'Celular');
    $brand = null; $img = null;
    foreach ($items as $p) { if ($brand === null && !empty($p['brand'])) { $brand = $p['brand']; } if ($img === null && !empty($p['image_url'])) { $img = $p['image_url']; } }

    $mk   = substr('model:' . $sig, 0, 60);
    $slug = slugify($title) . '-' . substr(sha1($mk), 0, 6);

    $upsert->execute([
        ':mk' => $mk, ':slug' => $slug, ':title' => $title, ':brand' => $brand,
        ':img' => $img, ':n' => count($items), ':stores' => count($stores), ':method' => 'model',
    ]);
    $getId->execute([$mk]);
    $gid = (int) $getId->fetchColumn();
    if ($gid <= 0) { continue; }

    $ids = implode(',', array_map(static fn(array $p): int => (int) $p['id'], $items));
    $db->exec("UPDATE products SET group_id = $gid WHERE id IN ($ids)");
    $groups++; $members += count($items);
}

// Recalcular conteos de TODOS los grupos y borrar los que quedaron vacíos
// (celulares que se movieron de su grupo exacto anterior).
$db->exec('UPDATE product_groups g SET
             member_count = (SELECT COUNT(*) FROM products WHERE group_id = g.id AND is_active = 1),
             store_count  = (SELECT COUNT(DISTINCT store_id) FROM products WHERE group_id = g.id AND is_active = 1)');
$deleted = $db->exec('DELETE FROM product_groups WHERE member_count = 0');

out(200, [
    'ok'             => true,
    'phone_products' => count($rows),
    'model_groups'   => $groups,
    'members_linked' => $members,
    'empty_deleted'  => (int) $deleted,
    'total_groups'   => (int) $db->query('SELECT COUNT(*) FROM product_groups')->fetchColumn(),
    'multi_store'    => (int) $db->query('SELECT COUNT(*) FROM product_groups WHERE store_count >= 2')->fetchColumn(),
]);
