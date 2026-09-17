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

/**
 * Envía un lote al /api/ingest TOLERANDO fallos transitorios del hosting
 * compartido. FatCow tira 500/502/timeout cuando varios crawlers pegan a la vez
 * (08:00–08:30). Antes cada crawler hacía exit(1) al primer no-200 → el run
 * salía ROJO y llegaba correo, aunque cientos de productos ya se habían
 * ingestado en lotes previos (por eso el panel de salud salía VERDE). Esto
 * unifica el patrón tolerante que ya usaban sitemap/copasa.
 *
 * $st es un acumulador que el llamador crea: ['sent'=>0,'lost'=>0,'consec'=>0].
 *   - HTTP 200            → cuenta enviado, resetea la racha, devuelve ''.
 *   - HTTP 401/403        → devuelve 'auth' (secret mal: el llamador aborta duro).
 *   - otro/red/timeout    → reintenta hasta 3× con backoff; si igual falla,
 *                            pierde el lote y suma a la racha. A los 8 lotes
 *                            seguidos totalmente caídos devuelve 'down' (ingest
 *                            realmente caído: abortar). Un blip aislado NO tumba
 *                            la corrida (la ingesta es idempotente por día; el
 *                            próximo run recupera lo perdido).
 * Devuelve '' mientras se pueda seguir.
 */
function oap_ingest_batch(\OjoAlPrecio\Web\Fetch\Http $http, string $url, string $key, array $items, array &$st): string
{
    if (!$items) { return ''; }
    $n = count($items);
    $last = '';
    for ($try = 1; $try <= 3; $try++) {
        $res = $http->postJson($url, ['items' => array_values($items)], ['X-Api-Key: ' . $key]);
        if ($res['status'] === 200) {
            $st['sent']   = ($st['sent'] ?? 0) + $n;
            $st['consec'] = 0;
            return '';
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return 'auth';
        }
        $last = ($res['error'] ?? '') !== '' ? $res['error'] : ('HTTP ' . $res['status']);
        if ($try < 3) { usleep(1500000 * $try); } // 1.5s, luego 3s
    }
    $st['lost']   = ($st['lost'] ?? 0) + $n;
    $st['consec'] = ($st['consec'] ?? 0) + 1;
    fwrite(STDERR, "  ⚠ ingesta falló ($last) — lote de $n descartado tras 3 intentos, sigo\n");
    return ($st['consec'] >= 8) ? 'down' : '';
}
