<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Scorers para el matcher difuso del comparador (slice 2b).
 * Compara dos productos de tiendas distintas (misma marca, ya "bloqueados")
 * y decide si son candidatos a ser el mismo producto.
 *
 * Guardarraíles (de la lección Remington): atributos numéricos (watts, gb…) y
 * subtipo (secadora ≠ plancha ≠ cepillo) DEBEN ser compatibles; si difieren,
 * no importa cuánto se parezcan imagen/título.
 */
final class Matcher
{
    /** Popcount de 0..15 (para Hamming por nibble hex). */
    private const POP = [0,1,1,2,1,2,2,3,1,2,2,3,2,3,3,4];

    /** Sinónimos ES/EN aplicados a los tokens del título. */
    private const SYN = [
        'zafiro' => 'sapphire', 'plateado' => 'silver', 'plata' => 'silver',
        'dorado' => 'gold', 'negro' => 'black', 'blanco' => 'white',
        'rojo' => 'red', 'azul' => 'blue', 'verde' => 'green', 'gris' => 'gray',
        'pulgadas' => 'in', 'pulg' => 'in',
    ];

    /** Palabras de subtipo: si ambos títulos traen una distinta, NO son el mismo. */
    private const SUBTYPES = [
        'secadora','plancha','cepillo','rizador','afeitadora','depiladora',
        'licuadora','batidora','microondas','horno','tostadora','cafetera','freidora',
        'refrigeradora','congelador','lavadora','secarropa','plancha','aspiradora',
        'televisor','tv','monitor','laptop','tablet','celular','telefono','audifonos',
        'parlante','bocina','camara','consola','impresora','ventilador','abanico',
        'silla','mesa','escritorio','sofa','cama','colchon',
    ];

    /** Distancia de Hamming entre dos dHash hex de 16 chars (0..64). */
    public static function hamming(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null || strlen($a) !== 16 || strlen($b) !== 16) {
            return null;
        }
        $a = strtolower($a); $b = strtolower($b);
        $d = 0;
        for ($i = 0; $i < 16; $i++) {
            $xa = hexdec($a[$i]); $xb = hexdec($b[$i]);
            $d += self::POP[$xa ^ $xb];
        }
        return $d;
    }

    /** Tokens normalizados (con sinónimos), sin duplicados ni stopwords cortas. */
    public static function tokens(?string $modelNorm): array
    {
        if ($modelNorm === null || $modelNorm === '') {
            return [];
        }
        $out = [];
        foreach (explode(' ', $modelNorm) as $t) {
            if ($t === '' || (strlen($t) < 2 && !ctype_digit($t))) { continue; }
            $out[self::SYN[$t] ?? $t] = true;
        }
        return array_keys($out);
    }

    /** Similitud de Jaccard entre dos conjuntos de tokens (0..1). */
    public static function jaccard(array $a, array $b): float
    {
        if (!$a || !$b) { return 0.0; }
        $sa = array_flip($a);
        $inter = 0;
        foreach ($b as $t) { if (isset($sa[$t])) { $inter++; } }
        $union = count($a) + count($b) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /** Atributos numéricos con unidad: ['w'=>2200,'gb'=>256,...]. */
    public static function attrs(?string $modelNorm): array
    {
        if (!$modelNorm) { return []; }
        $attrs = [];
        if (preg_match_all('/\b(\d+)(w|gb|tb|in|mah|hz|l|kg)\b/', $modelNorm, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $attrs[$x[2]] = (int) $x[1]; // si se repite la unidad, gana el último; suficiente para v1
            }
        }
        return $attrs;
    }

    /** ¿Compatibles? Para cada unidad presente en AMBOS, el valor debe coincidir. */
    public static function attrsCompatible(array $a, array $b): bool
    {
        foreach ($a as $k => $v) {
            if (isset($b[$k]) && $b[$k] !== $v) { return false; }
        }
        return true;
    }

    /** Subtipo detectado en los tokens (primero de la lista), o null. */
    public static function subtype(array $tokens): ?string
    {
        $set = array_flip($tokens);
        foreach (self::SUBTYPES as $s) {
            if (isset($set[$s])) { return $s; }
        }
        return null;
    }

    /**
     * Puntúa un par ya bloqueado por marca. Devuelve
     *   ['ok'=>bool, 'score'=>int, 'method'=>'image'|'title', 'img'=>?int, 'jac'=>float]
     * ok=false si un guardarraíl lo descarta o no llega al umbral.
     */
    public static function score(array $a, array $b): array
    {
        $no = ['ok' => false, 'score' => 0, 'method' => 'title', 'img' => null, 'jac' => 0.0];

        // Guardarraíl 1: atributos numéricos incompatibles.
        if (!self::attrsCompatible(self::attrs($a['model_norm'] ?? null), self::attrs($b['model_norm'] ?? null))) {
            return $no;
        }
        $ta = self::tokens($a['model_norm'] ?? null);
        $tb = self::tokens($b['model_norm'] ?? null);

        // Guardarraíl 2: subtipos distintos (secadora vs plancha).
        $sa = self::subtype($ta); $sb = self::subtype($tb);
        if ($sa !== null && $sb !== null && $sa !== $sb) {
            return $no;
        }

        // Guardarraíl 3: precio disparatado (misma cosa rara vez difiere >2.5x).
        $pa = $a['price'] ?? null; $pb = $b['price'] ?? null;
        if ($pa !== null && $pb !== null && $pa > 0 && $pb > 0) {
            $ratio = max($pa, $pb) / min($pa, $pb);
            if ($ratio > 2.5) { return $no; }
        }

        $img = self::hamming($a['img_dhash'] ?? null, $b['img_dhash'] ?? null);
        $jac = self::jaccard($ta, $tb);

        // El dHash 9x8 COLISIONA entre fotos de producto sobre fondo blanco
        // (objetos distintos → misma silueta → dist baja). Por eso la imagen NO
        // puede crear un match por sí sola: el TÍTULO/MODELO es el filtro
        // principal, y la imagen solo confirma.
        $imgConfirms    = $img !== null && $img <= 12; // misma/muy parecida foto
        $imgContradicts = $img !== null && $img > 22;  // fotos claramente distintas

        if ($imgContradicts) {
            return $no;
        }
        // Umbral de título: más bajo si la imagen confirma; más alto si no hay
        // imagen o es dudosa (evita falsos por título flojo).
        $jacMin = $imgConfirms ? 0.35 : 0.55;
        if ($jac < $jacMin) {
            return $no;
        }

        $score  = (int) round($jac * 100) + ($imgConfirms ? 20 : 0);
        $score  = min(100, $score);
        $method = $imgConfirms ? 'image' : 'title';

        if ($score < 55) { return $no; }
        return ['ok' => true, 'score' => $score, 'method' => $method, 'img' => $img, 'jac' => round($jac, 3)];
    }
}
