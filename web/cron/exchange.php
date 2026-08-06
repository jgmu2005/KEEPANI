<?php
declare(strict_types=1);

/**
 * Refresca el tipo de cambio USD desde el BCN (opcional; el peg está congelado).
 *   GET/POST /cron/exchange.php   Header: X-Api-Key: <ingest_api_key>
 * Guarda el valor en el ajuste `usd_rate` y en exchange_rates (histórico).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Fetch\Http;

header('Content-Type: application/json; charset=utf-8');

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (($cfg['ingest_api_key'] ?? '') === '' || !is_string($sent) || !hash_equals((string) $cfg['ingest_api_key'], $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

$http = new Http('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
$html = $http->get('https://www.bcn.gob.ni/');

if ($html !== null && preg_match('/([0-9]+\.[0-9]+)\s*\(NIOxUSD\)/', $html, $m)) {
    $rate = (float) $m[1];
    if ($rate > 1) {
        Settings::set($db, 'usd_rate', (string) $rate);
        $db->prepare('INSERT INTO exchange_rates (rate_date, usd_to_nio, source) VALUES (CURDATE(), ?, "BCN")
                      ON DUPLICATE KEY UPDATE usd_to_nio = VALUES(usd_to_nio)')->execute([$rate]);
        out(200, ['ok' => true, 'usd_rate' => $rate]);
    }
}

out(200, ['ok' => false, 'error' => 'No se pudo leer el BCN; se mantiene el valor actual', 'usd_rate' => Settings::get($db, 'usd_rate')]);
