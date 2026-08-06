<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Cuentas de usuario: registro, login, sesión y niveles.
 * Sesiones nativas de PHP + contraseñas con password_hash (bcrypt).
 */
final class Auth
{
    public const TIERS = ['free', 'onetime', 'subscriber'];
    public const UNLIMITED = 1000000;

    /** Tope de alertas por nivel (límites en settings) + vigencia de suscripción. */
    public static function alertLimit(PDO $db, string $tier, ?string $subscriptionUntil): int
    {
        $s = Settings::all($db);
        $free    = max(0, (int) ($s['limit_free'] ?? 5));
        $onetime = max(0, (int) ($s['limit_onetime'] ?? 15));
        if ($tier === 'subscriber') {
            $active = $subscriptionUntil === null || strtotime($subscriptionUntil) > time();
            return $active ? self::UNLIMITED : $free;
        }
        return $tier === 'onetime' ? $onetime : $free;
    }

    /** Dominios de correo desechable/temporal — bloqueados en el registro. */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com',
        'maildrop.cc', 'sharklasers.com', 'throwawaymail.com', 'fakeinbox.com',
        'dispostable.com', 'mohmal.com', 'emailondeck.com',
    ];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            session_start();
        }
    }

    /** Registra y deja la sesión iniciada. Lanza excepción si algo no valida. */
    public static function register(PDO $db, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido');
        }
        $domain = substr((string) strrchr($email, '@'), 1);
        if ($domain !== '' && in_array($domain, self::DISPOSABLE, true)) {
            throw new \RuntimeException('Usá un correo real (no temporal/desechable).');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres');
        }

        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn() !== false) {
            throw new \RuntimeException('Ese correo ya está registrado');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $db->prepare(
            "INSERT INTO users (email, password_hash, tier, is_verified) VALUES (?, ?, 'free', 0)"
        );
        $ins->execute([$email, $hash]);

        return self::login($db, $email, $password);
    }

    /** Inicia sesión. Devuelve el usuario o lanza excepción. */
    public static function login(PDO $db, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $st = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) {
            throw new \RuntimeException('Correo o contraseña incorrectos');
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];

        return self::currentUser($db) ?? throw new \RuntimeException('No se pudo iniciar sesión');
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Usuario actual (con su tope de alertas) o null si no hay sesión. */
    public static function currentUser(PDO $db): ?array
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $st = $db->prepare('SELECT id, email, tier, is_verified, subscription_until, donated_at, created_at FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) {
            return null;
        }
        $adminEmail = strtolower(trim((string) (Db::config()['admin_email'] ?? '')));
        $isAdmin = $adminEmail !== '' && strtolower($u['email']) === $adminEmail;

        return [
            'id'                 => (int) $u['id'],
            'email'              => $u['email'],
            'tier'               => $u['tier'],
            'alert_limit'        => self::alertLimit($db, $u['tier'], $u['subscription_until']),
            'is_admin'           => $isAdmin,
            'is_verified'        => (bool) $u['is_verified'],
            'subscription_until' => $u['subscription_until'],
            'donated_at'         => $u['donated_at'],
            'created_at'         => $u['created_at'],
        ];
    }

    /** Exige sesión; si no hay, responde 401 JSON y corta. */
    public static function requireUser(PDO $db): array
    {
        $u = self::currentUser($db);
        if (!$u) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Necesitás iniciar sesión']);
            exit;
        }
        return $u;
    }

    /** Exige que el usuario sea administrador; si no, responde 403 y corta. */
    public static function requireAdmin(PDO $db): array
    {
        $u = self::requireUser($db);
        if (empty($u['is_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Solo administradores']);
            exit;
        }
        return $u;
    }
}
