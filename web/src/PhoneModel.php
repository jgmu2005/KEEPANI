<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Detección de celulares y firma de MODELO para agruparlos entre tiendas.
 * Los celulares colisionan mal en el matcher por imagen (fondo blanco), pero
 * el MODELO se extrae bien del título — como hace Prexia.
 *
 * Firma: "marca|modelo" (sin distinguir almacenamiento, para maximizar el
 * agrupamiento entre tiendas; la diferencia de specs se ve en el comparador).
 */
final class PhoneModel
{
    private const BRANDS = [
        'apple','samsung','xiaomi','honor','huawei','motorola','tecno',
        'infinix','oppo','realme','zte','nokia','itel','alcatel','google',
    ];
    /** Pistas de que es un celular (no una TV/refri de la misma marca). */
    private const HINTS = [
        'iphone','galaxy','redmi','poco','moto','pixel','mate','nova','magic',
        'reno','narzo','spark','camon','pova','celular','smartphone','telefono',
    ];
    /** Palabras de OTROS productos de esas marcas → NO es celular. */
    private const NOT_PHONE = [
        'televisor',' tv ','tablet',' tab ','laptop','notebook','audifon','airpod',
        'watch','reloj','buds','parlante','bocina','soundbar','cargador','funda',
        'case','protector','refriger','lavadora','microonda','cocina','aire ',
        'monitor','impresora','mouse','teclado','cable','adaptador','power bank',
    ];
    /** Ruido a quitar del modelo (colores, genéricos, conectividad…). */
    private const STOP = [
        'celular','smartphone','telefono','movil','dual','sim','esim','5g','4g',
        'lte','liberado','desbloqueado','nuevo','sellado','original','garantia',
        'tienda','nicaragua','ram','rom','color','negro','blanco','azul','rojo',
        'verde','gris','dorado','plateado','morado','rosado','celeste','naranja',
        'black','white','blue','red','green','gray','grey','gold','silver','purple',
        'pink','titanium','titanio','phantom','cosmic','graphite','ultra5g',
    ];

    public static function isPhone(?string $brandNorm, ?string $modelNorm): bool
    {
        $b = (string) $brandNorm;
        $m = ' ' . (string) $modelNorm . ' ';
        if ($modelNorm === null || $modelNorm === '' || !in_array($b, self::BRANDS, true)) {
            return false;
        }
        foreach (self::NOT_PHONE as $x) {
            if (str_contains($m, $x)) { return false; }
        }
        foreach (self::HINTS as $h) {
            if (str_contains($m, $h)) { return true; }
        }
        return false;
    }

    /** Firma "marca|modelo" o null si no es celular / no hay modelo. */
    public static function signature(?string $brandNorm, ?string $modelNorm): ?string
    {
        if (!self::isPhone($brandNorm, $modelNorm)) {
            return null;
        }
        $tokens = [];
        foreach (explode(' ', (string) $modelNorm) as $t) {
            if ($t === '' || $t === $brandNorm) { continue; }
            if (in_array($t, self::STOP, true)) { continue; }
            if (preg_match('/^\d+(gb|tb|mah|mp|hz|w|mm|in)$/', $t)) { continue; } // unidades (RAM/almacenamiento/etc.)
            if (preg_match('/^\d+$/', $t) && (int) $t >= 33) { continue; }        // números grandes sueltos (almacenamiento)
            $tokens[] = $t;
            if (count($tokens) >= 6) { break; }                                    // firma corta
        }
        if (!$tokens) {
            return null;
        }
        return $brandNorm . '|' . implode(' ', $tokens);
    }
}
