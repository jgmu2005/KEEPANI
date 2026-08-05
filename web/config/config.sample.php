<?php
declare(strict_types=1);

/**
 * PLANTILLA DE CONFIGURACIÓN — Ojo al Precio
 *
 * 1. Copia este archivo a  config.php  (en la misma carpeta).
 * 2. Rellena tus credenciales reales de FatCow ahí.
 * 3. NUNCA subas config.php a git ni lo compartas. Solo config.sample.php.
 *
 * config.php queda ignorado por git (ver .gitignore).
 */

return [
    // --- Base de datos (FatCow: la creas en cPanel -> MySQL Databases) ---
    'db' => [
        'host'     => 'localhost',            // en shared hosting casi siempre localhost
        'name'     => 'TU_BASE_DE_DATOS',
        'user'     => 'TU_USUARIO_MYSQL',
        'password' => 'TU_PASSWORD_MYSQL',
        'charset'  => 'utf8mb4',
    ],

    // --- Clave del endpoint de ingesta ---
    // El scraper debe mandar este mismo valor en el header X-Api-Key.
    // Genera una larga y aleatoria, p.ej.:  bin2hex(random_bytes(24))
    'ingest_api_key' => 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA',

    // --- Administrador ---
    // El usuario registrado con ESTE correo es admin (accede al panel /admin.html).
    'admin_email' => 'tucorreo@ejemplo.com',
];
