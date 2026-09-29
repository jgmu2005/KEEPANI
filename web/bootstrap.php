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
        // Backoff con JITTER: si varios crawlers reintentan a la vez (ej. los 6 jobs
        // del sitemap contra FatCow), un backoff fijo los re-sincroniza y vuelven a
        // chocar. El jitter (0–1s) los dispersa.
        if ($try < 3) { usleep(1500000 * $try + random_int(0, 1000000)); }
    }
    $st['lost']   = ($st['lost'] ?? 0) + $n;
    $st['consec'] = ($st['consec'] ?? 0) + 1;
    fwrite(STDERR, "  ⚠ ingesta falló ($last) — lote de $n descartado tras 3 intentos, sigo\n");
    return ($st['consec'] >= 8) ? 'down' : '';
}

/**
 * Convierte a NIO (córdobas) la respuesta de track.php/history.php cuando el
 * producto es de una tienda en USD (electrofrioni, Samsung). Los clientes (el
 * sitio index.html y la extensión) muestran la moneda de `stats.currency` tal
 * cual; sin esto, un aire de US$776 se veía "US$776" en el widget en vez de
 * convertirlo a C$ como hacen producto.php/precio.php. Deja `usd_rate` y
 * `currency_native` por si el cliente quiere mostrar el equivalente ≈US$.
 */
function oap_response_to_nio(?array &$product, array &$stats, array &$history, float $usdRate): void
{
    $native = strtoupper((string) ($stats['currency'] ?? ($product['currency'] ?? 'NIO')));
    if ($native !== 'USD' || $usdRate <= 0) { return; } // ya en NIO o sin tasa

    foreach (['current', 'min', 'max', 'max_raw'] as $k) {
        if (isset($stats[$k]) && $stats[$k] !== null) { $stats[$k] = (float) $stats[$k] * $usdRate; }
    }
    $stats['currency']        = 'NIO';
    $stats['currency_native'] = $native;
    $stats['usd_rate']        = $usdRate;

    foreach ($history as &$__r) {
        foreach (['price_final', 'price_native', 'list_price'] as $k) {
            if (isset($__r[$k]) && $__r[$k] !== null) { $__r[$k] = (float) $__r[$k] * $usdRate; }
        }
        if (isset($__r['currency'])) { $__r['currency'] = 'NIO'; }
    }
    unset($__r);

    if ($product !== null) {
        $product['currency_native'] = $native;
        $product['currency']        = 'NIO';
    }
}
