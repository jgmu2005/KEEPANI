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

    /** id de la alerta existente del usuario para ese producto y tipo, o null. */
    public static function existingId(PDO $db, int $userId, int $productId, string $type = 'price'): ?int
    {
        $st = $db->prepare('SELECT id FROM alerts WHERE user_id = ? AND product_id = ? AND alert_type = ? AND is_active = 1 LIMIT 1');
        $st->execute([$userId, $productId, $type]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Crea una alerta. Para 'restock', $target va null (no hay precio objetivo). */
    public static function create(PDO $db, int $userId, int $productId, string $type = 'price', ?float $target = null): int
    {
        $st = $db->prepare(
            "INSERT INTO alerts (user_id, product_id, alert_type, target_price, target_currency, is_active)
             VALUES (?, ?, ?, ?, 'NIO', 1)"
        );
        $st->execute([$userId, $productId, $type, $type === 'restock' ? null : $target]);
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
        $sql = 'SELECT a.id, a.alert_type, a.target_price, a.created_at, a.last_triggered_at,
                       p.id AS product_id, p.title, p.image_url, p.url,
                       s.slug AS store, s.name AS store_name,
                       ph.price_final AS current_price, ph.currency, ph.in_stock
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
                'type'          => $r['alert_type'],
                'target_price'  => $r['target_price'] !== null ? (float) $r['target_price'] : null,
                'product_id'    => (int) $r['product_id'],
                'title'         => $r['title'],
                'image_url'     => $r['image_url'],
                'url'           => $r['url'],
                'store'         => $r['store'],
                'store_name'    => $r['store_name'],
                'current_price' => $r['current_price'] !== null ? (float) $r['current_price'] : null,
                'currency'      => $r['currency'] ?? 'NIO',
                'in_stock'      => (bool) $r['in_stock'],
                'triggered'     => $r['last_triggered_at'] !== null,
            ];
        }, $st->fetchAll());
    }

    /** Todas las alertas activas con su precio actual (para el cron). */
    public static function allActiveWithPrice(PDO $db): array
    {
        $sql = 'SELECT a.id, a.user_id, a.product_id, a.alert_type, a.target_price, a.last_triggered_at,
                       u.email, p.title, p.url,
                       ph.price_final AS price, ph.currency, ph.in_stock
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

    /**
     * Revisa las alertas activas y notifica (precio + restock). Reusable por
     * alerts_check (todas) y refresh_tracked (scope opcional por productos).
     * @param int[]|null $onlyProductIds si se pasa, solo revisa esos productos.
     */
    public static function notify(PDO $db, ?Mailer $mailer, array $cfg, string $siteName, string $base, ?array $onlyProductIds = null): array
    {
        $fmt  = static fn($v): string => 'C$' . number_format((float) $v, 2);
        $only = $onlyProductIds !== null ? array_flip(array_map('intval', $onlyProductIds)) : null;
        $key  = (string) ($cfg['ingest_api_key'] ?? '');

        $unsub = static function (int $id) use ($base, $key): string {
            return $base . '/api/alerts/unsubscribe.php?a=' . $id . '&t=' . hash_hmac('sha256', 'unsub:' . $id, $key);
        };

        $alerts = self::allActiveWithPrice($db);
        $checked = 0; $emailed = 0; $restocked = 0; $rearmed = 0; $noPrice = 0; $pending = 0;

        foreach ($alerts as $a) {
            if ($only !== null && !isset($only[(int) $a['product_id']])) { continue; }
            $checked++;
            $type    = $a['alert_type'] ?? 'price';
            $already = $a['last_triggered_at'] !== null;

            // ---- Restock: dispara al pasar de agotado a disponible ----
            if ($type === 'restock') {
                $inStock = (int) $a['in_stock'] === 1;
                if ($inStock) {
                    if ($already)  { continue; }
                    if (!$mailer)  { $pending++; continue; }
                    $priceLine = $a['price'] !== null ? '<p style="font-size:1.2rem;margin:8px 0"><b>' . $fmt($a['price']) . '</b></p>' : '';
                    $html = '<div style="font-family:system-ui,sans-serif;max-width:520px">'
                        . '<h2 style="color:#16a34a;margin:0 0 8px">🎉 ¡Volvió a estar disponible!</h2>'
                        . '<p style="margin:0 0 4px"><b>' . htmlspecialchars((string) $a['title']) . '</b></p>' . $priceLine
                        . '<p><a href="' . htmlspecialchars((string) $a['url']) . '" style="background:#0ea5e9;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Ver el producto ↗</a></p>'
                        . '<p style="color:#94a3b8;font-size:.8rem;margin-top:20px">Recibís esto por una alerta que creaste en ' . htmlspecialchars($siteName) . '. '
                        . '<a href="' . htmlspecialchars($unsub((int) $a['id'])) . '" style="color:#94a3b8">Dejar de recibir estas alertas</a>.</p></div>';
                    $res = $mailer->send((string) $a['email'], '🎉 Volvió a estar disponible: ' . $a['title'], $html);
                    if ($res['ok']) { self::markTriggered($db, (int) $a['id'], (float) ($a['price'] ?? 0)); $emailed++; $restocked++; }
                    else { $pending++; }
                } elseif ($already) {
                    self::rearm($db, (int) $a['id']); $rearmed++;
                }
                continue;
            }

            // ---- Precio ----
            if ($a['price'] === null) { $noPrice++; continue; }
            $price  = (float) $a['price'];
            $target = (float) $a['target_price'];
            if ($price <= $target) {
                if ($already) { continue; }
                if (!$mailer) { $pending++; continue; }
                $html = '<div style="font-family:system-ui,sans-serif;max-width:520px">'
                    . '<h2 style="color:#16a34a;margin:0 0 8px">📉 ¡Bajó de precio!</h2>'
                    . '<p style="margin:0 0 4px"><b>' . htmlspecialchars((string) $a['title']) . '</b></p>'
                    . '<p style="font-size:1.3rem;margin:8px 0"><b>' . $fmt($price) . '</b> <span style="color:#64748b;font-size:.9rem">(tu objetivo: ' . $fmt($target) . ')</span></p>'
                    . '<p><a href="' . htmlspecialchars((string) $a['url']) . '" style="background:#0ea5e9;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Ver el producto ↗</a></p>'
                    . '<p style="color:#94a3b8;font-size:.8rem;margin-top:20px">Recibís esto por una alerta que creaste en ' . htmlspecialchars($siteName) . '. '
                    . '<a href="' . htmlspecialchars($unsub((int) $a['id'])) . '" style="color:#94a3b8">Dejar de recibir estas alertas</a>.</p></div>';
                $res = $mailer->send((string) $a['email'], '📉 Bajó de precio: ' . $a['title'], $html);
                if ($res['ok']) { self::markTriggered($db, (int) $a['id'], $price); $emailed++; }
                else { $pending++; }
            } elseif ($already) {
                self::rearm($db, (int) $a['id']); $rearmed++;
            }
        }

        return ['checked' => $checked, 'emailed' => $emailed, 'restocked' => $restocked,
                'rearmed' => $rearmed, 'no_price' => $noPrice, 'mail_pending' => $pending,
                'mail_configured' => (bool) $mailer];
    }
}
