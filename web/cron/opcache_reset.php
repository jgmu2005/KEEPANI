<?php
declare(strict_types=1);

/**
 * CRON/UTIL — diagnóstico y reset de PHP OPcache.
 *
 * En hosting compartido (FatCow) OPcache puede seguir sirviendo el bytecode VIEJO
 * de un archivo aunque lo subas por SFTP. Este endpoint reporta qué versión de
 * PhoneModel está CARGADA (probando signature() sobre un caso conocido) y resetea
 * OPcache para que la próxima request recompile los .php actualizados.
 *
 *   GET /cron/opcache_reset.php   Header: X-Api-Key: <ingest_api_key>
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }

try {
    $cfg = Db::config();
    $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $cfg['ingest_api_key'] ?? '';
    if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
        out(401, ['ok' => false, 'error' => 'No autorizado']);
    }

    // ¿Qué código de PhoneModel está CARGADO ahora mismo? (antes del reset)
    $campana = \OjoAlPrecio\Web\PhoneModel::signature(null, \OjoAlPrecio\Web\Normalizer::model('Samsung Campana Extractora Drija a Pared'));
    $phone   = \OjoAlPrecio\Web\PhoneModel::signature(null, \OjoAlPrecio\Web\Normalizer::model('HONOR X5d DUAL SIM 4GB RAM 128GB'));

    $file    = dirname(__DIR__) . '/src/PhoneModel.php';
    $enabled = false;
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        $enabled = is_array($st) ? (bool) ($st['opcache_enabled'] ?? false) : false;
    }

    $reset = 'no disponible';
    if (function_exists('opcache_reset')) {
        $reset = @opcache_reset() ? 'reseteado' : 'falló';
    }
    // Además, invalidar explícitamente el archivo por si el reset global está limitado.
    if (function_exists('opcache_invalidate') && is_file($file)) {
        @opcache_invalidate($file, true);
    }

    out(200, [
        'ok'                     => true,
        'opcache_enabled'        => $enabled,
        'phonemodel_size_disk'   => @filesize($file),
        'phonemodel_mtime_disk'  => @date('Y-m-d H:i:s', (int) @filemtime($file)),
        'test_campana_signature' => $campana,   // null = código NUEVO ; string = código VIEJO cacheado
        'test_phone_signature'   => $phone,      // debe ser "honor|x5d"
        'codigo_cargado'         => $campana === null ? 'NUEVO (ok)' : 'VIEJO (opcache servía bytecode cacheado)',
        'opcache_reset'          => $reset,
        'nota'                   => 'Ahora re-corré build_phone_groups; la próxima request ya recompila PhoneModel.',
    ]);
} catch (\Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
