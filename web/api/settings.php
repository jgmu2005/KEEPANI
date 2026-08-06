<?php
declare(strict_types=1);

/** GET /api/settings.php — PÚBLICO. Ajustes de branding para el sitio. */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $db = Db::conn();
    $settings = Settings::publicView($db);
    // Site key de Turnstile (pública) para renderizar el captcha en el registro.
    $settings['turnstile_site_key'] = (string) (Db::config()['turnstile_site_key'] ?? '');
    echo json_encode(['ok' => true, 'settings' => $settings],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
