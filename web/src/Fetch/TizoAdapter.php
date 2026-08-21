<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Fetch;

/**
 * Adaptador on-demand para Tizo (soytizo.com). El catálogo vive en una API REST
 * aparte (api.tizo.app) que exige un token de invitado:
 *   POST /auth/customers/guest_user?idDevice={hex}   →  { data: { token } }
 *   GET  /products/product/{productId}   Header: Authorization: {token}   (sin Bearer)
 *
 * El SKU que trackeamos es el productId; la URL pública es
 *   {base}/home/product/{productId}/option/{idProductOption}.
 */
final class TizoAdapter implements StoreAdapter
{
    private const API = 'https://api.tizo.app/api/v1';
    private ?string $token = null;

    public function __construct(
        private Http   $http,
        private string $slug,
        private string $baseUrl,
        private string $currency = 'NIO',
        private bool   $taxIncluded = true,
        private float  $taxRate = 0.15
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    /** Genera (y cachea) un token de invitado. POST sin cuerpo, idDevice en la query. */
    private function token(): ?string
    {
        if ($this->token !== null) {
            return $this->token !== '' ? $this->token : null;
        }
        $this->token = '';
        $dev = bin2hex(random_bytes(16));
        $ch = curl_init(self::API . '/auth/customers/guest_user?idDevice=' . $dev);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $j = json_decode((string) $body, true);
        $tok = $j['data']['token'] ?? null;
        if ($tok) { $this->token = (string) $tok; }
        return $tok ? (string) $tok : null;
    }

    public function fetchBySku(string $sku): ?NormalizedProduct
    {
        $tok = $this->token();
        if ($tok === null) {
            return null;
        }
        $data = $this->http->getJson(self::API . '/products/product/' . rawurlencode($sku), ['Authorization: ' . $tok]);
        if (!is_array($data)) {
            return null;
        }
        $p = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        if (!isset($p['productId'])) {
            return null;
        }
        return TizoMapper::map($p, $this->slug, $this->baseUrl, $this->currency, $this->taxIncluded, $this->taxRate);
    }

    /** URL pública: .../home/product/{id}/option/{opt} → el id es el sku. */
    public function fetchByUrl(string $url, string $sku): ?NormalizedProduct
    {
        if (preg_match('~/home/product/(\d+)~', $url, $m)) {
            $sku = $m[1];
        }
        return $this->fetchBySku($sku);
    }
}
