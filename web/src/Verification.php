<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Verificación de correo: genera un token, lo guarda y manda el email con el link.
 * Reutilizado por el registro y por "reenviar verificación".
 */
final class Verification
{
    /** URL base de la app (funciona en cualquier dominio: raíz o subdirectorio). */
    public static function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Raíz de la app desde SCRIPT_NAME:
        //  1) quita el tramo de endpoint (/api|cron|cli/...) → sirve para esos scripts;
        //  2) quita el archivo .php de las páginas top-level (producto.php, precio.php…),
        //     que antes dejaba la raíz como ".../precio.php" y rompía las URLs (canónica,
        //     WhatsApp: ".../precio.php/precio/123/...").
        $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $path = preg_replace('#/(api|cron|cli)/.*$#', '', $path);
        $path = preg_replace('#/[^/]+\.php$#', '', $path);
        return $scheme . '://' . $host . rtrim((string) $path, '/');
    }

    /**
     * Genera y envía el correo de verificación. Devuelve true si se envió.
     * (false = SMTP no configurado → el llamador puede auto-verificar para no bloquear.)
     */
    public static function issueAndSend(PDO $db, int $userId, string $email): bool
    {
        $mailer = Mailer::fromSettings($db);
        if (!$mailer) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE users SET verify_token = ? WHERE id = ?')->execute([$token, $userId]);

        $site = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
        $url  = self::baseUrl() . '/api/auth/verify.php?token=' . $token;

        $html = '<div style="font-family:system-ui,sans-serif;max-width:480px">'
            . '<h2 style="color:#0369a1">Confirmá tu correo</h2>'
            . '<p>Gracias por registrarte en <b>' . htmlspecialchars((string) $site) . '</b>. '
            . 'Tocá el botón para activar tu cuenta y poder crear alertas de precio:</p>'
            . '<p><a href="' . htmlspecialchars($url) . '" '
            . 'style="background:#0ea5e9;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">Verificar mi correo</a></p>'
            . '<p style="color:#94a3b8;font-size:.8rem;margin-top:18px">Si no fuiste vos, ignorá este correo.</p></div>';

        $res = $mailer->send($email, 'Confirmá tu correo · ' . $site, $html);
        return !empty($res['ok']);
    }
}
