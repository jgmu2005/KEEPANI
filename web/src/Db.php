<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

use PDO;

/**
 * Fábrica de conexión PDO a MySQL. Lee credenciales de config.php.
 * Una sola conexión reutilizable por request.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = require dirname(__DIR__) . '/config/config.php';
        $db  = $cfg['db'];

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /** Devuelve toda la config (para leer la api key, etc.). */
    public static function config(): array
    {
        return require dirname(__DIR__) . '/config/config.php';
    }
}
