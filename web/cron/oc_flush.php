<?php
// Reset de OPcache SIN dependencias (nombre nuevo para que opcache no lo tenga
// cacheado). Fuerza recompilar todos los .php actualizados en la próxima request.
// Borralo después de usarlo. GET /cron/oc_flush.php
header('Content-Type: application/json');

$r = ['opcache_present' => false, 'opcache_enabled' => false, 'reset' => 'no disponible'];

if (function_exists('opcache_get_status')) {
    $r['opcache_present'] = true;
    $s = @opcache_get_status(false);
    $r['opcache_enabled'] = is_array($s) ? (bool) ($s['opcache_enabled'] ?? false) : false;
    if (is_array($s) && isset($s['opcache_statistics']['num_cached_scripts'])) {
        $r['cached_scripts'] = (int) $s['opcache_statistics']['num_cached_scripts'];
    }
}
if (function_exists('opcache_reset')) {
    $r['reset'] = @opcache_reset() ? 'reseteado' : 'falló';
}

echo json_encode($r, JSON_PRETTY_PRINT);
