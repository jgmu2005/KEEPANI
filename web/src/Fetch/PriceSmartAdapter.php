<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador on-demand para PriceSmart (Bloomreach Discovery).
 * Trae UN producto por su pid con search_type=keyword (q=pid) en el mismo BFF
 * que usa el crawler. El BFF exige UA de navegador + Referer/Origin, por eso
 * hace su propio POST (no usa el Http inyectado, que va con UA de bot).
 */
final class PriceSmartAdapter implements StoreAdapter
{
    private const BFF = '/api/br_discovery/getProductsByKeyword';
    private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const FL  = 'pid,title,brand,slug,master_sku,price_NI,original_price_without_saving_NI,inventory_NI,availability_NI,thumb_image,currency,fractionDigits';
    private const BR  = [
        'account_id' => '7024',
        'auth_key'   => 'ev7libhybjg5h1d1',
        'domain_key' => 'pricesmart_bloomreach_io_es',
        'view_id'    => 'NI',
    ];

    public function __construct(
        private Http   $http, // no se usa para el BFF; se mantiene por la interfaz
        private string $slug,
        private string $baseUrl,
        private string $currency = 'NIO',
        private bool   $taxIncluded = false,
        private float  $taxRate = 0.15
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    /** En PriceSmart el pid basta; la URL no hace falta. */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        return $this->fetchBySku($sku);
    }

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        $base = rtrim($this->baseUrl, '/');
        $ref  = $base . '/es-ni';
        $payload = [[
            'q' => $sku, 'search_type' => 'keyword', 'start' => 0, 'rows' => 10, 'fq' => [],
            'account_id' => self::BR['account_id'], 'auth_key' => self::BR['auth_key'],
            'domain_key' => self::BR['domain_key'], 'view_id' => self::BR['view_id'],
            'request_id' => 1786053095902, '_br_uid_2' => 'uid=1:v=15.0:ts=1:hc=1',
            'url' => $ref, 'fl' => self::FL,
        ]];

        $body = $this->post($base . self::BFF, $payload, $ref, $base);
        if ($body === null) {
            return null;
        }
        $j = json_decode($body, true);
        $docs = $j['response']['docs'] ?? [];

        // Match EXACTO por pid/master_sku (keyword podría traer parecidos).
        foreach ($docs as $d) {
            if ((string) ($d['pid'] ?? '') === $sku || (string) ($d['master_sku'] ?? '') === $sku) {
                return PriceSmartMapper::map($d, $this->slug, $base, $this->currency, $this->taxIncluded, $this->taxRate);
            }
        }
        return null;
    }

    /** POST JSON al BFF con UA de navegador + Referer/Origin (lo que exige). */
    private function post(string $url, array $payload, string $referer, string $origin): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Referer: ' . $referer,
                'Origin: ' . $origin,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
    }
}
