<?php
declare(strict_types=1);

/**
 * CRON/UTIL — diagnóstico y reset de PHP OPcache.
 *
 * En hosting compartido (FatCow) OPcache puede seguir sirviendo el bytecode VIEJO
 * de un archivo aunque lo subas por SFTP (si validate_timestamps=0 o el
 * revalidate_freq no venció). Este endpoint:
 *   1) reporta si OPcache está activo y qué versión de PhoneModel está CARGADA
 *      (probando signature() sobre un caso conocido), y
 *   2) resetea OPcache para que la próxima request recompile los .php actualizados.
 *
 *   GET /cron/opcache_reset.php   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Normalizer;
use OjoAlPrecio\Web\PhoneModel;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }

$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// ¿Qué código de PhoneModel está CARGADO ahora mismo? (antes del reset)
$campana = PhoneModel::signature(null, Normalizer::model('Samsung Campana Extractora Drija a Pared'));
$phone   = PhoneModel::signature(null, Normalizer::model('HONOR X5d DUAL SIM 4GB RAM 128GB'));

$file   = dirname(__DIR__) . '/src/PhoneModel.php';
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$cached = null;
if (function_exists('opcache_get_status')) {
    $full = @opcache_get_status(true);
    if (is_array($full) && isset($full['scripts'][$file])) {
        $cached = [
            'timestamp'     => $full['scripts'][$file]['timestamp'] ?? null,
            'last_used'     => $full['scripts'][$file]['last_used'] ?? null,
        ];
    }
}

$before = [
    'opcache_enabled'        => is_array($status) ? ($status['opcache_enabled'] ?? false) : false,
    'phonemodel_file'        => $file,
    'phonemodel_mtime_disk'  => @filemtime($file),
    'phonemodel_size_disk'   => @filesize($file),
    'phonemodel_cached'      => $cached,
    // La prueba de fuego: con el código NUEVO, campana debe ser null y el celular no.
    'test_campana_signature' => $campana,   // null = código NUEVO ok ; string = código VIEJO cacheado
    'test_phone_signature'   => $phone,      // debe ser "honor|x5d"
    'codigo_cargado'         => $campana === null ? 'NUEVO (ok)' : 'VIEJO (opcache sirviendo bytecode cacheado)',
];

$reset = function_exists('opcache_reset') ? (@opcache_reset() ? 'reseteado' : 'falló opcache_reset()') : 'opcache_reset() no disponible';

out(200, [
    'ok'    => true,
    'before'=> $before,
    'reset' => $reset,
    'nota'  => 'Si codigo_cargado era VIEJO: ahora corré de nuevo build_phone_groups; la próxima request ya recompila PhoneModel.',
]);
