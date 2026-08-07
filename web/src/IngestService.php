<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Persiste un batch normalizado del scraper:
 *   - resuelve la tienda por slug (debe existir/seed)
 *   - upsert del producto por (store_id, external_sku)  [enriquece metadatos]
 *   - inserta la fila del histórico (append-only, 1 por producto por día)
 *
 * Idempotente por día gracias al índice único (product_id, captured_date):
 * si el cron corre dos veces el mismo día, se actualiza esa fila, no duplica.
 */
final class IngestService
{
    public function __construct(private PDO $db) {}

    /** @param array $items lista de registros normalizados. @return array resumen */
    public function ingest(array $items): array
    {
        $stores = $this->storeSlugToId();

        $inserted = 0; $updated = 0; $skipped = 0; $errors = [];

        foreach ($items as $i => $it) {
            $slug = $it['store'] ?? null;
            $sku  = isset($it['sku']) ? (string) $it['sku'] : null;

            if (!$slug || !$sku || !isset($stores[$slug])) {
                $skipped++;
                $errors[] = "item[$i]: tienda o sku inválidos (store=" . var_export($slug, true) . ")";
                continue;
            }

            try {
                $this->db->beginTransaction();

                $productId = $this->upsertProduct((int) $stores[$slug], $sku, $it);
                $isNew     = $this->insertPrice($productId, $it);

                $this->db->commit();
                $isNew ? $inserted++ : $updated++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $skipped++;
                $errors[] = "item[$i] ($slug/$sku): " . $e->getMessage();
            }
        }

        return [
            'ok'       => true,
            'received' => count($items),
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /** @return array<string,int> slug => store_id */
    private function storeSlugToId(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT id, slug FROM stores') as $row) {
            $map[$row['slug']] = (int) $row['id'];
        }
        return $map;
    }

    /** Inserta o actualiza el producto; devuelve su id. Refresca metadatos sin borrarlos. */
    private function upsertProduct(int $storeId, string $sku, array $it): int
    {
        $capturedAt = $this->capturedAt($it);

        $sel = $this->db->prepare(
            'SELECT id FROM products WHERE store_id = ? AND external_sku = ? LIMIT 1'
        );
        $sel->execute([$storeId, $sku]);
        $id = $sel->fetchColumn();

        // Sanea UTF-8 inválido en el texto scrapeado (ej. OG de Gallo) para que
        // json_encode no falle después en cualquier endpoint que lo devuelva.
        $it['title']     = self::scrub($it['title'] ?? null);
        $it['brand']     = self::scrub($it['brand'] ?? null);
        $it['image_url'] = self::scrub($it['image_url'] ?? null);
        $it['url']       = self::scrub($it['url'] ?? null);

        // Identidad para el comparador (ver Normalizer / docs/comparador-matcher.md).
        $ean       = isset($it['ean']) && $it['ean'] !== '' ? (string) $it['ean'] : null;
        $brandNorm = Normalizer::brand($it['brand'] ?? null);
        $modelNorm = Normalizer::model($it['title'] ?? null);

        if ($id !== false) {
            // COALESCE(nuevo, actual): solo sobrescribe si viene valor no nulo.
            $upd = $this->db->prepare(
                'UPDATE products
                    SET title = COALESCE(?, title),
                        brand = COALESCE(?, brand),
                        brand_norm = COALESCE(?, brand_norm),
                        model_norm = COALESCE(?, model_norm),
                        ean = COALESCE(?, ean),
                        image_url = COALESCE(?, image_url),
                        url = COALESCE(?, url),
                        category_external_id = COALESCE(?, category_external_id),
                        last_seen_at = ?
                  WHERE id = ?'
            );
            $upd->execute([
                $it['title'] ?? null,
                $it['brand'] ?? null,
                $brandNorm,
                $modelNorm,
                $ean,
                $it['image_url'] ?? null,
                $it['url'] ?? null,
                $it['category_id'] ?? null,
                $capturedAt,
                (int) $id,
            ]);
            return (int) $id;
        }

        $ins = $this->db->prepare(
            'INSERT INTO products (store_id, external_sku, ean, category_external_id, url, title, brand, brand_norm, model_norm, image_url, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $storeId,
            $sku,
            $ean,
            $it['category_id'] ?? null,
            $it['url'] ?? '',
            $it['title'] ?? null,
            $it['brand'] ?? null,
            $brandNorm,
            $modelNorm,
            $it['image_url'] ?? null,
            $capturedAt,
            $capturedAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Inserta la fila de precio del día. Devuelve true si fue nueva (no update por rerun). */
    private function insertPrice(int $productId, array $it): bool
    {
        $capturedAt = $this->capturedAt($it);
        $capturedDate = substr($capturedAt, 0, 10);

        $sql = 'INSERT INTO price_history
                    (product_id, captured_date, captured_at, price_native, price_final,
                     price_usd, list_price, discount_pct, currency, in_stock)
                VALUES (:pid, :cdate, :cat, :pnat, :pfin, NULL, :lp, :disc, :cur, :stock)
                ON DUPLICATE KEY UPDATE
                    captured_at  = VALUES(captured_at),
                    price_native = VALUES(price_native),
                    price_final  = VALUES(price_final),
                    list_price   = VALUES(list_price),
                    discount_pct = VALUES(discount_pct),
                    currency     = VALUES(currency),
                    in_stock     = VALUES(in_stock)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pid'   => $productId,
            ':cdate' => $capturedDate,
            ':cat'   => $capturedAt,
            ':pnat'  => $it['price_native'] ?? null,
            ':pfin'  => $it['price_final']  ?? null,
            ':lp'    => $it['list_price']   ?? null,
            ':disc'  => $it['discount_pct'] ?? null,
            ':cur'   => $it['currency'] ?? 'NIO',
            ':stock' => !empty($it['in_stock']) ? 1 : 0,
        ]);

        // rowCount()==1 => INSERT nuevo; ==2 => UPDATE por ON DUPLICATE KEY.
        return $stmt->rowCount() === 1;
    }

    /** Quita bytes UTF-8 inválidos (evita que json_encode devuelva false luego). */
    private static function scrub(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        return function_exists('mb_scrub')
            ? mb_scrub($s, 'UTF-8')
            : (string) @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /** Normaliza captured_at a 'Y-m-d H:i:s' UTC. */
    private function capturedAt(array $it): string
    {
        $raw = $it['captured_at'] ?? 'now';
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
