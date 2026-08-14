<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Analiza si una "oferta" es real, habitual o dudosa, comparando el precio
 * actual contra el historial del propio producto.
 *
 * Devuelve HECHOS (veredicto + números); la frase para el usuario la arma el
 * front con su propio formato de moneda. Es CONSERVADOR para marcar "dudosa":
 * exige varios registros y un margen claro, porque acusar a una tienda de
 * descuento falso sin datos suficientes sería injusto.
 *
 *   verdict: 'low'      → está en (o cerca de) su precio más bajo registrado
 *            'fake'     → la tienda anuncia descuento pero no es más barato de lo habitual
 *            'typical'  → cerca de su precio de siempre (sin señal fuerte)
 *            null       → sin datos suficientes para opinar
 *   reason:  'new_low' | 'at_low' | 'inflated_ref' | 'permanent_discount' | 'usual'
 */
final class DealAnalyzer
{
    private const MIN_POINTS_LOW  = 2; // afirmar "precio más bajo"
    private const MIN_POINTS_FAKE = 7; // acusar "oferta dudosa" (conservador)

    /**
     * @param float[] $series precios finales históricos (orden temporal), nullables toleran filtrado
     */
    public static function analyze(?float $price, ?float $list, array $series): ?array
    {
        $pts = array_values(array_filter(
            array_map(static fn($v) => $v === null ? null : (float) $v, $series),
            static fn($v) => $v !== null && $v > 0
        ));
        $n = count($pts);
        if ($price === null || $price <= 0 || $n < self::MIN_POINTS_LOW) {
            return null;
        }

        $lo   = min($pts);
        $hi   = max($pts);
        $typ  = self::median($pts);
        $shows = $list !== null && $list > $price;

        // 1) "Buen momento" SOLO si está por DEBAJO de su precio habitual (mediana).
        //    Estar en el mínimo no alcanza: si el mínimo == el habitual (precio normal,
        //    o el precio volvió a la normalidad tras un pico), NO es una oferta.
        if ($price <= $lo * 1.01 && $price < $typ * 0.97) {
            return self::result('low', 'new_low', $typ, $list, $n);
        }

        // 2) Oferta dudosa (solo si la tienda anuncia descuento y hay historia suficiente).
        if ($shows && $n >= self::MIN_POINTS_FAKE) {
            if ($list > $hi * 1.05) {
                return self::result('fake', 'inflated_ref', $typ, $list, $n);
            }
            if ($price >= $typ * 0.99) {
                return self::result('fake', 'permanent_discount', $typ, $list, $n);
            }
        }

        // 3) Sin señal fuerte: precio habitual (se muestra solo en la ficha).
        return self::result('typical', 'usual', $typ, $list, $n);
    }

    private static function result(string $verdict, string $reason, float $typical, ?float $list, int $n): array
    {
        return [
            'verdict' => $verdict,
            'reason'  => $reason,
            'typical' => round($typical, 2),
            'list'    => $list,
            'points'  => $n,
        ];
    }

    private static function median(array $a): float
    {
        sort($a);
        $n   = count($a);
        $mid = intdiv($n, 2);
        return $n % 2 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
    }
}
