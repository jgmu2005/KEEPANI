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
 *
 * El título de cada tienda trae ruido distinto ("galaxy a57 pantalla 6 17 02"
 * vs "a57 almacenamiento"), así que la firma:
 *   - salta palabras redundantes (galaxy, cel, colores…) sin cortar,
 *   - CORTA en el primer término de spec/marketing (pantalla, almacenamiento,
 *     promocional, cubo…), que es donde termina el nombre del modelo,
 *   - sólo acepta un número suelto si sigue a una palabra-línea (iphone 17,
 *     magic 8, note 50); si no, lo trata como dimensión y corta.
 */
final class PhoneModel
{
    private const BRANDS = [
        'apple','samsung','xiaomi','honor','huawei','motorola','tecno',
        'infinix','oppo','realme','zte','nokia','itel','alcatel','google',
    ];
    /** Submarca/línea → marca padre, para inferir la marca del título cuando la
     *  tienda no la expone (ej. telcmax: "GALAXY A15" sin campo marca → samsung). */
    private const HINT_BRAND = [
        'iphone' => 'apple', 'galaxy' => 'samsung', 'redmi' => 'xiaomi', 'poco' => 'xiaomi',
        'moto' => 'motorola', 'pixel' => 'google', 'magic' => 'honor', 'reno' => 'oppo',
        'narzo' => 'realme', 'camon' => 'tecno', 'spark' => 'tecno', 'pova' => 'tecno',
        'mate' => 'huawei', 'nova' => 'huawei',
    ];
    /** Pistas de que es un celular (no una TV/refri de la misma marca). */
    private const HINTS = [
        'iphone','galaxy','redmi','poco','moto','pixel','mate','nova','magic',
        'reno','narzo','spark','camon','pova','celular','smartphone','telefono',
    ];
    /** Palabras de OTROS productos de esas marcas → NO es celular. */
    private const NOT_PHONE = [
        'televisor',' tv ','tablet',' tab ','laptop','notebook','audifon','airpod',
        'watch','reloj','buds',' band ',' fit ','parlante','bocina','soundbar',
        'cargador','funda','case','protector','refriger','lavadora','microonda',
        'cocina','aire ','monitor','impresora','mouse','teclado','cable',
        'adaptador','power bank','router','modem','scooter','xpad',
    ];

    /** Palabra-línea: un número suelto que la sigue es parte del modelo. */
    private const MODEL_LINE = [
        'iphone','note','magic','reno','nova','pixel','mate','pova','camon',
        'spark','hot','narzo','edge','poco','redmi','fold','flip',
    ];
    /** Variantes que SÍ distinguen y se conservan. */
    private const TIER = [
        'pro','max','plus','ultra','lite','mini','fe','neo','prime','power','play','air','se',
    ];
    /** Redundante o cosmético: se salta SIN cortar el modelo. */
    private const SKIP = [
        'galaxy','cel','celular','smartphone','telefono','movil','de','del','la','el',
        'y','e','para','un','una','dual','sim','esim','5g','4g','lte','liberado',
        'desbloqueado','nuevo','sellado','original','garantia','tienda','nicaragua','tec','porta',
        'color','negro','blanco','azul','rojo','verde','gris','dorado','plateado',
        'morado','rosado','celeste','naranja','black','white','blue','red','green',
        'gray','grey','gold','silver','purple','pink','titanium','titanio','phantom',
        'cosmic','graphite',
    ];
    /** Empieza la parte de specs/marketing/bundle → se corta el modelo acá. */
    private const BOUNDARY = [
        'pantalla','almacenamiento','memoria','promocional','promo','cubo','incluye',
        'cover','con','colores','surtidos','surtido','control','gamepad','snapdragon',
        'dimensity','helio','exynos','camara','bateria','pulgadas','pulg','banda',
        'reacondicionado','refurbished','gratis','regalo','obsequio','combo','kit',
        'bundle','mas','ram','rom','gb','tb','mah','mp','hz','cm','mm','ghz','w',
    ];

    /**
     * Marca efectiva: la dada si es conocida; si no, la infiere del título
     * (primer token que sea una marca, o una submarca → marca padre). Así los
     * celulares de tiendas que NO exponen marca (telcmax) igual se agrupan.
     */
    public static function resolveBrand(?string $brandNorm, ?string $modelNorm): ?string
    {
        if ($brandNorm !== null && in_array($brandNorm, self::BRANDS, true)) {
            return $brandNorm;
        }
        $toks = explode(' ', (string) $modelNorm);
        foreach ($toks as $t) {
            if ($t !== '' && in_array($t, self::BRANDS, true)) { return $t; }
        }
        foreach ($toks as $t) {
            if (isset(self::HINT_BRAND[$t])) { return self::HINT_BRAND[$t]; }
        }
        return $brandNorm;
    }

    public static function isPhone(?string $brandNorm, ?string $modelNorm): bool
    {
        $brandNorm = self::resolveBrand($brandNorm, $modelNorm);
        $b = (string) $brandNorm;
        $m = ' ' . (string) $modelNorm . ' ';
        if ($modelNorm === null || $modelNorm === '' || !in_array($b, self::BRANDS, true)) {
            return false;
        }
        foreach (self::NOT_PHONE as $x) {
            if (str_contains($m, $x)) { return false; }
        }
        // "dual sim" / "esim" es señal fuerte de celular aunque el título no traiga
        // una submarca conocida (ej. "HONOR X5d DUAL SIM 4GB RAM 128GB").
        if (str_contains($m, ' sim ') || str_contains($m, ' esim ')) { return true; }
        foreach (self::HINTS as $h) {
            if (str_contains($m, $h)) { return true; }
        }
        return false;
    }

    /** Firma "marca|modelo" o null si no es celular / no hay modelo. */
    public static function signature(?string $brandNorm, ?string $modelNorm): ?string
    {
        $brandNorm = self::resolveBrand($brandNorm, $modelNorm);
        if (!self::isPhone($brandNorm, $modelNorm)) {
            return null;
        }

        $tokens   = [];
        $prevLine = false; // el último token conservado es una palabra-línea

        foreach (explode(' ', (string) $modelNorm) as $t) {
            if ($t === '' || $t === $brandNorm) { continue; }
            if (in_array($t, self::SKIP, true)) { continue; }
            if (in_array($t, self::BOUNDARY, true)) { break; }
            if (preg_match('/^\d+(gb|tb|mah|mp|hz|w|mm|cm|in|ghz)$/', $t)) { break; } // 128gb, 6000mah…
            if (preg_match('/^(?=.*\d)[a-z0-9]{7,}$/', $t)) { continue; }            // SKU interno (mzb0jsyus…)
            // Código de fábrica (x6887, a276b, x6895b) — pero NO el nombre comercial
            // (a27, x5d, g200): 4+ dígitos, o 3 dígitos con letra final.
            if (preg_match('/^[a-z](\d{4,}[a-z]?|\d{3}[a-z])$/', $t)) { continue; }

            if (preg_match('/^\d+$/', $t)) {
                if (!$prevLine) { break; }        // número suelto sin línea previa = dimensión → corta
                $tokens[] = $t; $prevLine = false; continue; // "iphone 17", "magic 8"
            }

            $tokens[]  = $t;
            $prevLine  = in_array($t, self::MODEL_LINE, true);
            if (count($tokens) >= 5) { break; }
        }

        if (!$tokens) {
            return null;
        }
        return $brandNorm . '|' . implode(' ', $tokens);
    }
}
