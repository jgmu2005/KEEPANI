<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Rate limiting simple por IP + acción, sobre la tabla rate_events.
 * Uso: if (!RateLimiter::allow($db, $ip, 'register', 5, 3600)) { bloquear }
 */
final class RateLimiter
{
    /** IP del cliente (considera proxies conocidos). */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                return substr($ip, 0, 45);
            }
        }
        return '0.0.0.0';
    }

    /**
     * ¿Se permite esta acción? Cuenta los eventos de (ip, action) en la ventana;
     * si ya alcanzó el máximo devuelve false; si no, registra el evento y devuelve true.
     */
    public static function allow(PDO $db, string $ip, string $action, int $max, int $windowSec): bool
    {
        $windowSec = max(1, $windowSec);

        $st = $db->prepare(
            'SELECT COUNT(*) FROM rate_events
              WHERE ip = ? AND action = ? AND created_at > (NOW() - INTERVAL ' . $windowSec . ' SECOND)'
        );
        $st->execute([$ip, $action]);
        if ((int) $st->fetchColumn() >= $max) {
            return false;
        }

        $db->prepare('INSERT INTO rate_events (ip, action) VALUES (?, ?)')->execute([$ip, $action]);
        // Limpieza oportunista de lo viejo de este ip/action.
        $db->prepare('DELETE FROM rate_events WHERE ip = ? AND action = ? AND created_at < (NOW() - INTERVAL 1 DAY)')
           ->execute([$ip, $action]);
        return true;
    }
}
