<?php
declare(strict_types=1);

/**
 * GET /api/admin/opcache_reset.php — resetea el OPcache de PHP.
 *
 * FatCow cachea con OPcache y NO revalida por mtime, así que tras subir un .php por
 * SFTP puede seguir corriendo el código viejo. Pegar/abrir este endpoint fuerza un
 * opcache_reset() → la próxima request recompila todo desde disco. Útil tras CADA
 * deploy por SFTP.
 *
 * Auth: header X-Api-Key con el ingest_api_key, O sesión de administrador.
 * (Si el OPcache es por-proceso y no compartido, puede hacer falta abrirlo
 *  un par de veces para cubrir todos los procesos.)
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Auth;

header('Content-Type: application/json; charset=utf-8');

$db  = Db::conn();
$cfg = Db::config();
$key  = $cfg['ingest_api_key'] ?? '';
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!($key !== '' && is_string($sent) && hash_equals($key, $sent))) {
    Auth::requireAdmin($db); // sin key válida → exige admin logueado (corta si no)
}

$out = ['ok' => true];
$out['reset'] = function_exists('opcache_reset') ? opcache_reset() : null;
if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    $out['enabled']        = $s['opcache_enabled'] ?? null;
    $out['cached_scripts'] = $s['opcache_statistics']['num_cached_scripts'] ?? null;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
