<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Ajustes del sitio (clave→valor), editables desde el panel de admin.
 *
 *  - PUBLIC_KEYS: branding que el sitio público puede leer (settings.php).
 *  - SMTP_KEYS:   credenciales de correo. PRIVADAS — NUNCA salen por el
 *                 endpoint público; solo el admin las lee (con la contraseña
 *                 enmascarada) y las escribe.
 */
final class Settings
{
    public const PUBLIC_KEYS = [
        'site_name', 'tagline', 'hero_text', 'logo_emoji',
        'donate_kofi', 'donate_paypal', 'footer_note',
    ];

    public const SMTP_KEYS = [
        'smtp_host', 'smtp_port', 'smtp_secure',
        'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name',
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

    /** Solo branding público (settings.php). NUNCA incluye SMTP. */
    public static function publicView(PDO $db): array
    {
        $all = self::all($db);
        $out = [];
        foreach (self::PUBLIC_KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        return $out;
    }

    /** Vista para el admin: branding + SMTP, con la contraseña ENMASCARADA. */
    public static function adminView(PDO $db): array
    {
        $all = self::all($db);
        $out = [];
        foreach (self::PUBLIC_KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        foreach (self::SMTP_KEYS as $k) {
            $out[$k] = $k === 'smtp_pass' ? '' : ($all[$k] ?? '');
        }
        $out['smtp_pass_set'] = !empty($all['smtp_pass']); // ¿hay contraseña guardada?
        return $out;
    }

    /** Credenciales SMTP crudas (para el Mailer). Uso interno/admin. */
    public static function smtp(PDO $db): array
    {
        $all = self::all($db);
        $out = [];
        foreach (self::SMTP_KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        return $out;
    }

    /** Guarda un ajuste (solo claves de las listas blancas). */
    public static function set(PDO $db, string $k, string $v): void
    {
        if (!in_array($k, self::PUBLIC_KEYS, true) && !in_array($k, self::SMTP_KEYS, true)) {
            return;
        }
        $st = $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$k, $v]);
        self::$cache = null;
    }
}
