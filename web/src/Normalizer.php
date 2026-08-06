<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Normalización de marca y título/modelo para el matcher del comparador.
 * Objetivo: dejar strings comparables entre tiendas (sin acentos, minúsculas,
 * unidades unificadas, sin ruido) para "blocking" y similitud.
 *
 * Los SINÓNIMOS de idioma (zafiro=sapphire) se aplican en el matcher, no acá,
 * para no perder el término original.
 */
final class Normalizer
{
    public static function brand(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }
        $b = self::deaccentLower($brand);
        $b = preg_replace('/[^a-z0-9 ]+/', ' ', $b);
        $b = preg_replace('/\s+/', ' ', trim($b));
        return $b === '' ? null : $b;
    }

    public static function model(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $t = self::deaccentLower($title);
        // Unifica unidades comunes (antes de quitar símbolos).
        $t = preg_replace('/(\d+)\s*"/', '$1in', $t);            // 55"  -> 55in
        $t = preg_replace('/(\d+)\s*watts?\b/', '$1w', $t);      // 2200 watts -> 2200w
        $t = preg_replace('/(\d+)\s*(gb|tb|ml|kg|mah|hz|pulg(?:adas)?)\b/', '$1$2', $t);
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t);
        $t = preg_replace('/\b(nicaragua|nueva|new)\b/', ' ', $t); // ruido geográfico/relleno
        $t = preg_replace('/\s+/', ' ', trim($t));
        return $t === '' ? null : $t;
    }

    private static function deaccentLower(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ñ' => 'n', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
