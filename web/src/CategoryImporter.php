<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;
use OjoAlPrecio\Web\Fetch\Http;

/**
 * Importa TODAS las categorías de una tienda VTEX desde su árbol de catálogo:
 *   GET {base}/api/catalog_system/pub/category/tree/50
 * Aplana el árbol anidado y hace upsert en la tabla `categories`.
 */
final class CategoryImporter
{
    /** @return array{imported:int} */
    public static function import(PDO $db, array $store): array
    {
        $base = rtrim((string) $store['base_url'], '/');
        // El endpoint del árbol rate-limita el UA de bot (429). Con UA de navegador + Referer pasa.
        $http = new Http('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
        $tree = $http->getJson($base . '/api/catalog_system/pub/category/tree/50', ['Referer: ' . $base . '/']);
        if (!is_array($tree)) {
            throw new \RuntimeException('No se pudo obtener el árbol de categorías (VTEX respondió sin datos, puede ser rate-limit momentáneo).');
        }

        $rows = [];
        self::flatten($tree, null, 1, $rows);

        $stmt = $db->prepare(
            'INSERT INTO categories
                (store_id, external_id, name, parent_external_id, url, has_children, level)
             VALUES (:sid, :eid, :name, :parent, :url, :hc, :lvl)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), parent_external_id = VALUES(parent_external_id),
                url = VALUES(url), has_children = VALUES(has_children), level = VALUES(level)'
        );

        $storeId = (int) $store['id'];
        foreach ($rows as $r) {
            $stmt->execute([
                ':sid'    => $storeId,
                ':eid'    => $r['id'],
                ':name'   => $r['name'],
                ':parent' => $r['parent'],
                ':url'    => $r['url'],
                ':hc'     => $r['has_children'] ? 1 : 0,
                ':lvl'    => $r['level'],
            ]);
        }

        return ['imported' => count($rows)];
    }

    /** Recorre el árbol anidado y arma una lista plana con padre y nivel. */
    private static function flatten(array $nodes, ?int $parent, int $level, array &$out): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n) || !isset($n['id'])) {
                continue;
            }
            $id = (int) $n['id'];
            $out[] = [
                'id'           => $id,
                'name'         => (string) ($n['name'] ?? ''),
                'parent'       => $parent,
                'url'          => isset($n['url']) ? substr((string) $n['url'], 0, 500) : null,
                'has_children' => !empty($n['hasChildren']),
                'level'        => $level,
            ];
            if (!empty($n['children']) && is_array($n['children'])) {
                self::flatten($n['children'], $id, $level + 1, $out);
            }
        }
    }
}
