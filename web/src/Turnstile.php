<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Verificación server-side de Cloudflare Turnstile.
 * Si el secret está vacío, el captcha está desactivado (devuelve true).
 */
final class Turnstile
{
    public static function verify(string $secret, string $token, string $ip): bool
    {
        if ($secret === '') {
            return true; // desactivado
        }
        if ($token === '') {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if ($body === false) {
            return false;
        }
        $data = json_decode((string) $body, true);
        return is_array($data) && !empty($data['success']);
    }
}
