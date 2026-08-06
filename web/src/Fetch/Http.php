<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Wrapper mínimo de cURL. Los adaptadores de la fase 1 (VTEX y OG) solo
 * necesitan HTTP GET; nada de navegador headless.
 *
 * Buenas prácticas: User-Agent honesto, timeouts, y un pequeño retry con
 * backoff para no romper el histórico por un fallo puntual de red.
 */
final class Http
{
    public function __construct(
        private string $userAgent = 'OjoAlPrecioBot/0.1 (+https://agrotecnicaragua.com/ojoalprecio; price-history tracker)',
        private int    $timeout = 20,
        private int    $maxRetries = 2
    ) {}

    /** GET que devuelve el body como string, o null si falla tras los reintentos. */
    public function get(string $url, array $headers = []): ?string
    {
        $attempt = 0;
        while ($attempt <= $this->maxRetries) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => array_merge(['Accept: */*'], $headers),
                CURLOPT_ENCODING       => '', // acepta gzip/deflate
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body !== false && $code >= 200 && $code < 300) {
                return (string) $body;
            }
            // 4xx (salvo 429) no se reintenta: el recurso no va a aparecer.
            if ($code >= 400 && $code < 500 && $code !== 429) {
                return null;
            }
            $attempt++;
            if ($attempt <= $this->maxRetries) {
                usleep((int) (300000 * $attempt)); // backoff: 0.3s, 0.6s
            }
        }
        return null;
    }

    /** GET + json_decode a array asociativo, o null. */
    public function getJson(string $url, array $headers = []): ?array
    {
        $body = $this->get($url, array_merge(['Accept: application/json'], $headers));
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * POST de un payload JSON. Devuelve ['status'=>int, 'body'=>string].
     * Lo usa el crawler remoto (GitHub Actions) para enviar lotes a /api/ingest.
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        // La ingesta hace upserts en MySQL: dale más margen que un GET.
        $timeout = max($this->timeout, 40);

        $attempt = 0;
        $lastErr = '';
        while ($attempt <= $this->maxRetries) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => array_merge(
                    ['Content-Type: application/json', 'Accept: application/json'],
                    $headers
                ),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $code >= 200 && $code < 300) {
                return ['status' => $code, 'body' => (string) $body, 'error' => ''];
            }
            // 4xx (salvo 429) = error de cliente/permiso: no tiene sentido reintentar.
            if ($code >= 400 && $code < 500 && $code !== 429) {
                return ['status' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $err];
            }
            // Red caída (code 0), 429 o 5xx → reintento con backoff.
            $lastErr = $err !== '' ? $err : "HTTP $code";
            $attempt++;
            if ($attempt <= $this->maxRetries) {
                sleep($attempt); // 1s, 2s
            }
        }
        return ['status' => 0, 'body' => '', 'error' => $lastErr];
    }
}
