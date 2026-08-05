<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Ajustes del sitio (clave→valor), editables desde el panel de admin.
 * El sitio público lee estos valores para su branding.
 */
final class Settings
{
    /** Claves que el sitio público puede leer/editar el admin. */
    public const KEYS = [
        'site_name', 'tagline', 'hero_text', 'logo_emoji',
        'donate_kofi', 'donate_paypal', 'footer_note',
    ];

    private static ?array $cache = null;

    /** Todos los ajustes como mapa k=>v. */
    public static function all(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach ($db->query('SELECT k, v FROM settings') as $r) {
            $out[$r['k']] = $r['v'];
        }
        return self::$cache = $out;
    }

    /** Solo las claves públicas (con default '' si faltan). */
    public static function publicView(PDO $db): array
    {
        $all = self::all($db);
        $out = [];
        foreach (self::KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        return $out;
    }

    /** Guarda un ajuste (solo claves de la lista blanca). */
    public static function set(PDO $db, string $k, string $v): void
    {
        if (!in_array($k, self::KEYS, true)) {
            return;
        }
        $st = $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$k, $v]);
        self::$cache = null;
    }
}
