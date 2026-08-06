<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * dHash perceptual de imágenes para el matcher del comparador.
 * Compartido por el cron de FatCow (cron/hash_images.php) y el crawler remoto
 * de GitHub Actions (cli/hash_images_remote.php), para que el hash sea IDÉNTICO
 * calcúlelo quien lo calcule (mismo umbral de Hamming ~13-14).
 */
final class ImageHash
{
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /** Baja los bytes de una imagen, o null si falla. */
    public static function fetch(string $url, int $timeout = 12): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300 && $body !== '') ? (string) $body : null;
    }

    /** dHash de 64 bits en hex (16 chars), o null si no se pudo decodificar. */
    public static function dhashHex(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }
        $w = 9; $h = 8;
        $small = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($small, 255, 255, 255); // por si hay transparencia
        imagefilledrectangle($small, 0, 0, $w, $h, $white);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagedestroy($img);

        $gray = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                $gray[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }
        imagedestroy($small);

        $bits = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $gray[$y][$x] > $gray[$y][$x + 1] ? '1' : '0';
            }
        }
        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex((int) bindec(substr($bits, $i, 4)));
        }
        return $hex; // 16 chars
    }

    /** ¿Es un dHash hex válido (16 chars hex)? Para validar lo que llega por API. */
    public static function isValidHex(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', $s);
    }
}
