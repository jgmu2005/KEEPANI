<?php
declare(strict_types=1);

/**
 * CRON de alertas — lo dispara cron-job.org después del refresco diario.
 *
 *   GET/POST /cron/alerts_check.php   Header: X-Api-Key: <ingest_api_key>
 *
 * Para cada alerta activa: si el precio actual <= objetivo y aún no avisó,
 * manda el email y marca. Si el precio vuelve a subir por encima del objetivo,
 * re-arma la alerta (para que vuelva a avisar en la próxima bajada).
 */

require dirname(__DIR__) . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Alerts;
use OjoAlPrecio\Web\Mailer;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(0);

function out(int $s, array $p): never { http_response_code($s); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$db  = Db::conn();
$cfg = Db::config();
$sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expected = $cfg['ingest_api_key'] ?? '';
if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
    out(401, ['ok' => false, 'error' => 'No autorizado']);
}

// Mantenimiento diario: bajar a 'free' las suscripciones vencidas.
$db->exec("UPDATE users SET tier = 'free'
            WHERE tier = 'subscriber' AND subscription_until IS NOT NULL AND subscription_until < NOW()");

$mailer   = Mailer::fromSettings($db);
$siteName = Settings::all($db)['site_name'] ?? 'Ojo al Precio';
$base     = Verification::baseUrl();

// Revisa TODAS las alertas (precio + restock) y notifica.
$summary = Alerts::notify($db, $mailer, $cfg, $siteName, $base);

out(200, ['ok' => true] + $summary);
