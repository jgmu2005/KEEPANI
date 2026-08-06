<?php
declare(strict_types=1);

/**
 * CRON — construcción de grupos de producto (comparador, slice 2a: exacto).
 * Lo dispara cron-job.org.  Ver docs/comparador-matcher.md.
 *
 *   GET/POST /cron/build_groups.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Agrupa por IDENTIFICADOR EXACTO, solo clusters que abarcan ≥2 TIENDAS:
 *   - Unicomer: mismo external_sku entre lacuracao/radioshack/tropigas.
 *   - EAN/GTIN: mismo ean entre tiendas distintas (VTEX y cualquiera que lo traiga).
 *
 * Idempotente: upsert por match_key. La parte difusa/imagen (Nivel B/C/D) es
 * otro job (slice 2b), cuando img_dhash esté poblado.
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','à'=>'a','è'=>'e','ñ'=>'n','ü'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    $s = substr((string) $s, 0, 60);
    return $s !== '' ? $s : 'producto';
}

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Los GROUP_CONCAT de ids pueden ser largos.
$db->exec('SET SESSION group_concat_max_len = 1000000');

$UNI = "SELECT CONCAT('uni:', p.external_sku) AS match_key,
               COUNT(*) AS n, COUNT(DISTINCT p.store_id) AS stores,
               MAX(p.title) AS title, MAX(p.brand) AS brand, MAX(p.image_url) AS image_url,
               GROUP_CONCAT(p.id) AS ids
          FROM products p
          JOIN stores s ON s.id = p.store_id
         WHERE s.slug IN ('lacuracao','radioshack','tropigas')
           AND p.is_active = 1 AND p.external_sku <> ''
         GROUP BY p.external_sku
        HAVING COUNT(DISTINCT p.store_id) >= 2";

$EAN = "SELECT CONCAT('ean:', p.ean) AS match_key,
               COUNT(*) AS n, COUNT(DISTINCT p.store_id) AS stores,
               MAX(p.title) AS title, MAX(p.brand) AS brand, MAX(p.image_url) AS image_url,
               GROUP_CONCAT(p.id) AS ids
          FROM products p
         WHERE p.ean IS NOT NULL AND p.ean <> '' AND p.is_active = 1
         GROUP BY p.ean
        HAVING COUNT(DISTINCT p.store_id) >= 2";

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

foreach (['uni' => $UNI, 'ean' => $EAN] as $method => $sql) {
    $clusters = $db->query($sql)->fetchAll(); // materializar antes de escribir
    foreach ($clusters as $c) {
        $mk   = (string) $c['match_key'];
        $slug = slugify((string) ($c['title'] ?? 'producto')) . '-' . substr(sha1($mk), 0, 6);

        $upsert->execute([
            ':mk'     => $mk,
            ':slug'   => $slug,
            ':title'  => $c['title'] ?: null,
            ':brand'  => $c['brand'] ?: null,
            ':img'    => $c['image_url'] ?: null,
            ':n'      => (int) $c['n'],
            ':stores' => (int) $c['stores'],
            ':method' => $method,
        ]);
        $getId->execute([$mk]);
        $gid = (int) $getId->fetchColumn();
        if ($gid <= 0) { continue; }

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $c['ids']))));
        if ($ids) {
            $in = implode(',', $ids); // ints saneados
            $db->exec("UPDATE products SET group_id = $gid WHERE id IN ($in)");
            $members += count($ids);
        }
        $groups++;
    }
}

out(200, [
    'ok'              => true,
    'groups_upserted' => $groups,
    'members_linked'  => $members,
    'total_groups'    => (int) $db->query('SELECT COUNT(*) FROM product_groups')->fetchColumn(),
    'multi_store'     => (int) $db->query('SELECT COUNT(*) FROM product_groups WHERE store_count >= 2')->fetchColumn(),
]);
