<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 mínimo para el lado web: OjoAlPrecio\Web\ -> web/src/
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'OjoAlPrecio\\Web\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
