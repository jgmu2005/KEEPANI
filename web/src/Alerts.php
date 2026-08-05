<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Alertas de precio de un usuario sobre un producto.
 * El tope por nivel (free/donor) se aplica en el endpoint create.
 */
final class Alerts
{
    /** # de alertas activas del usuario (para el tope). */
    public static function countActive(PDO $db, int $userId): int
    {
        $st = $db->prepare('SELECT COUNT(*) FROM alerts WHERE user_id = ? AND is_active = 1');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** id de la alerta existente del usuario para ese producto, o null. */
    public static function existingId(PDO $db, int $userId, int $productId): ?int
    {
        $st = $db->prepare('SELECT id FROM alerts WHERE user_id = ? AND product_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$userId, $productId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function create(PDO $db, int $userId, int $productId, float $target): int
    {
        $st = $db->prepare(
            "INSERT INTO alerts (user_id, product_id, target_price, target_currency, is_active)
             VALUES (?, ?, ?, 'NIO', 1)"
        );
        $st->execute([$userId, $productId, $target]);
        return (int) $db->lastInsertId();
    }

    /** Actualiza el objetivo y re-arma la alerta (para que vuelva a avisar). */
    public static function updateTarget(PDO $db, int $alertId, float $target): void
    {
        $st = $db->prepare('UPDATE alerts SET target_price = ?, last_triggered_at = NULL WHERE id = ?');
        $st->execute([$target, $alertId]);
    }

    /** Borra (definitivo) una alerta del usuario. */
    public static function delete(PDO $db, int $userId, int $alertId): void
    {
        $st = $db->prepare('DELETE FROM alerts WHERE id = ? AND user_id = ?');
        $st->execute([$alertId, $userId]);
    }

    /** Alertas del usuario con la info del producto y su precio actual. */
    public static function listForUser(PDO $db, int $userId): array
    {
        $sql = 'SELECT a.id, a.target_price, a.created_at, a.last_triggered_at,
                       p.id AS product_id, p.title, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name,
                       ph.price_final AS current_price, ph.currency
                  FROM alerts a
                  JOIN products p ON p.id = a.product_id
                  JOIN stores s ON s.id = p.store_id
                  LEFT JOIN price_history ph ON ph.id = (
                        SELECT id FROM price_history WHERE product_id = p.id ORDER BY captured_at DESC LIMIT 1)
                 WHERE a.user_id = ? AND a.is_active = 1
                 ORDER BY a.created_at DESC';
        $st = $db->prepare($sql);
        $st->execute([$userId]);

        return array_map(static function (array $r): array {
            return [
                'id'            => (int) $r['id'],
                'target_price'  => (float) $r['target_price'],
                'product_id'    => (int) $r['product_id'],
                'title'         => $r['title'],
                'image_url'     => $r['image_url'],
                'url'           => $r['url'],
                'store'         => $r['store'],
                'store_name'    => $r['store_name'],
                'current_price' => $r['current_price'] !== null ? (float) $r['current_price'] : null,
                'currency'      => $r['currency'] ?? 'NIO',
                'triggered'     => $r['last_triggered_at'] !== null,
            ];
        }, $st->fetchAll());
    }

    /** Todas las alertas activas con su precio actual (para el cron). */
    public static function allActiveWithPrice(PDO $db): array
    {
        $sql = 'SELECT a.id, a.user_id, a.product_id, a.target_price, a.last_triggered_at,
                       u.email, p.title, p.url,
                       ph.price_final AS price, ph.currency
                  FROM alerts a
                  JOIN users u ON u.id = a.user_id
                  JOIN products p ON p.id = a.product_id
                  LEFT JOIN price_history ph ON ph.id = (
                        SELECT id FROM price_history WHERE product_id = a.product_id ORDER BY captured_at DESC LIMIT 1)
                 WHERE a.is_active = 1';
        return $db->query($sql)->fetchAll();
    }

    public static function markTriggered(PDO $db, int $alertId, float $price): void
    {
        $db->prepare('UPDATE alerts SET last_triggered_at = NOW() WHERE id = ?')->execute([$alertId]);
        $db->prepare('INSERT INTO notifications (alert_id, price_at_trigger, channel) VALUES (?, ?, ?)')
           ->execute([$alertId, $price, 'email']);
    }

    /** Re-arma (para que vuelva a avisar si baja otra vez). */
    public static function rearm(PDO $db, int $alertId): void
    {
        $db->prepare('UPDATE alerts SET last_triggered_at = NULL WHERE id = ?')->execute([$alertId]);
    }
}
