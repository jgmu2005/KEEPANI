<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Clasificador de categoría CROSS-STORE por keywords del título.
 *
 * El catálogo NI está dominado por ferreteras (Sinsa/Siman), así que además de
 * las categorías "de tienda departamental" (celulares, TV, línea blanca…) hay
 * varios buckets ferreteros (plomería, eléctrico, iluminación, herramientas,
 * ferretería, construcción, pintura). classify() devuelve la CLAVE del primer
 * bucket cuyo keyword aparezca (orden de RULES = prioridad), o null.
 */
final class CategoryClassifier
{
    /** clave => etiqueta con emoji. Orden de exhibición de los chips. */
    public const LABELS = [
        'celulares'       => '📱 Celulares',
        'computo'         => '💻 Computación',
        'tv'              => '📺 Televisores',
        'videojuegos'     => '🎮 Videojuegos',
        'audio'           => '🎧 Audio',
        'belleza'         => '💇 Cuidado personal',
        'climatizacion'   => '❄️ Climatización',
        'linea_blanca'    => '🧺 Línea blanca',
        'electro_pequeno' => '☕ Electrodomésticos',
        'iluminacion'     => '💡 Iluminación',
        'electrico'       => '🔌 Material eléctrico',
        'plomeria'        => '🚿 Plomería',
        'herramientas'    => '🔧 Herramientas',
        'pintura'         => '🎨 Pintura',
        'construccion'    => '🧱 Construcción',
        'ferreteria'      => '🔩 Ferretería',
        'automotriz'      => '🚗 Automotriz',
        'hogar'           => '🛋️ Hogar y baño',
        'jardin'          => '🌱 Jardín y piscina',
        'deportes'        => '🚴 Deportes',
        'bebes_juguetes'  => '🧸 Bebés y juguetes',
    ];

    private const BRANDS = [
        'apple','samsung','xiaomi','honor','huawei','motorola','tecno',
        'infinix','oppo','realme','zte','nokia','itel','alcatel','google',
    ];

    /**
     * Reglas en ORDEN de prioridad. El primer bucket con un keyword presente gana.
     * Compuestos antes que genéricos; lo "electrónico" antes que lo ferretero para
     * no robarse términos (ej. "tubo led" → iluminación antes que "tubo" → plomería).
     */
    private const RULES = [
        'computo'         => ['laptop','notebook','computadora','desktop','monitor',' cpu ','teclado','mouse','impresora','tablet',' ipad','macbook','disco duro',' ssd ','memoria ram','router','tarjeta grafica','all in one','proyector','webcam',' ups ','no break','nobreak',' rack ','servidor','camara de seguridad','camara smart','camara ip','camara wifi','patch cord','disco solido'],
        'tv'              => ['televisor',' tv ','smart tv','pantalla led','pantalla uhd','pantalla qled','led tv','android tv','google tv'],
        'videojuegos'     => ['playstation',' ps5',' ps4','xbox','nintendo','consola','joystick','videojuego','control inalambrico','mando de'],
        'audio'           => ['audifon','auricular','parlante','bocina','soundbar','barra de sonido','earbud','airpod','microfono','teatro en casa','home theater'],
        'belleza'         => ['secadora de pelo','secadora de cabello','plancha de pelo','plancha de cabello','rizador','afeitadora','rasuradora','maquina de afeitar','depiladora','perfume','maquillaje','shampoo','cepillo dental','labial','lip smacker','balsamo labial','esmalte de una','crema facial','desodorante'],
        'climatizacion'   => ['aire acondicionado','split ','minisplit','mini split',' btu','ventilador','abanico','calefactor','purificador de aire','deshumidificador'],
        'linea_blanca'    => ['refrigerad','refri ','congelador','lavadora','secadora de ropa','cocina de','estufa','microondas','lavavajilla','lavaplatos','campana extractor'],
        'electro_pequeno' => ['licuadora','batidora','cafetera','freidora','air fryer','tostadora','sandwichera','plancha de ropa','aspiradora','olla arrocera','olla de presion','olla electrica','extractor de jugo','waflera',' grill'],

        'iluminacion'     => ['bombillo','bombilla',' foco','luminaria','lampara','reflector led','panel led','plafon','spot led',' spot ','tubo led','cinta led','riel de sobreponer','farol','candil','linterna','reflector','luz led',' led '],
        'electrico'       => ['breaker','conduit','tomacorriente','toma corriente','interruptor','apagador','protector de voltaje','regulador de voltaje','extension electrica','multitoma','multicontacto','enchufe','tablero electrico','panel electrico','contactor','transformador','timbre','cable ','alambre electrico','caja electrica','canaleta','tubo emt','fotocelda','sensor de movimiento',' rele ','relevador','portalampara','porta lampara'],
        'plomeria'        => ['tuberia','tubo pvc','tubo cpvc',' pvc',' cpvc','codo ',' tee ','union lisa','union rosca','adaptador macho','adaptador hembra','tapon pvc','tapon hembra','tapon macho','niple','reduccion','valvula','llave de agua','llave angulo','llave de paso','llave de pase','llave ducha','llave para pantry','pantry','monomando','grifo','griferia','lavamano','lavamanos','lavatrastos','sanitario','inodoro','teflon','manguera','sifon','coladera','cisterna','tinaco','tanque para agua','tanque de agua','rotoplas','bomba de agua','bomba para agua','bomba para','presion de bomba',' bomba','purificador de agua','ducha','regadera','flotador','fluxometro','trampa p'],
        'herramientas'    => ['taladro','tenaza','prensa','sargento','escuadra','formon','serrucho','serucho','alicate','cuchilla','navaja','cortaperno','corta perno','corta pernos','destornillador','atornillador','martillo','mazo','sierra','serrote','esmeril','amoladora','pulidora','lijadora','disco de corte','disco corte',' broca','juego de broca','cincel','lima ','nivel de','cinta metrica','flexometro','llave inglesa','llave ajustable','llave allen','llave corona','llave combinada','llave de impacto','llave impacto','juego de llave','dado ',' pinza','gato hidraulico','soldadora','pistola de calor','pistola de silicon','remachadora','grapadora','tijera','tornillo de banco','morsa','engrapadora','carretilla','escalera','herramienta','sopladora','soplador','ahoyadora','neumatica','desarmador'],
        'pintura'         => ['pintura','pintar','brocha','rodillo','pincel','thinner','barniz','esmalte','laca',' spray','aerosol','espatula','lija de agua','sellador para','base coat','anticorrosivo'],
        'construccion'    => ['cemento','repello','mortero','concreto','baldosa','loseta','ceramica','porcelanato','azulejo','gypsum','panel de yeso','paral','block ','ladrillo','arena','grava','piedrin','teja','lamina','laminas','zinc','varilla','malla electrosoldada','pega ','pegamix','boquilla','adhesivo para','sellador de concreto','impermeabilizante','tapagotera','aislante','sika','aditivo','durock','plycem','fibrocemento','durlock'],
        'ferreteria'      => ['tornillo','clavo','tuerca','arandela',' perno','bisagra','cerradura','candado','pasador','aldaba','silicon','silicona','pegamento','cinta aislante','cinta adhesiva','cinta metrica','teip','herraje','abrazadera','gancho','cadena','remache','grapa','tarugo','ancla','angulo de','platina','malla','alambre','argolla','rueda','riel para','tope','manija','jaladera','llavin','chapa ','guante ','guantes','guardapolvo','protector de puerta','soldadura'],
        'automotriz'      => ['refrigerante','anticongelante','forro de volante','protector solar para auto','para automovil','para vehiculo','llanta','neumatico','aceite de motor','aceite para motor',' bujia','limpiaparabrisas','plumilla','cubre asiento','tapete para auto','bateria de auto','filtro de aceite','filtro de aire de motor','liga de motor','aditivo para combustible'],
        'hogar'           => ['mueble','colchon','sofa','sillon',' silla','mesa de','escritorio',' cama ','ropero','closet','sarten','olla ','cacerola','vajilla','cubiertos','cuchillo de cocina','cortina','sabana','edredon','almohada','cobija','organizador','estanteria','escurridor','cobertor','utensilio','balde','cesto','basurero','percha','tabla de planchar','hielera',' termo','mantel','tapete','alfombra','espejo','perchero','zapatera','jabonera','papelera','portarrollo','portarollo','toallero','toalla de','dispensador de jabon','dispensador para jabon','accesorios p bano','accesorios para bano','set accesorios','vaso p cepillo','bandeja','panuelo','caja de seguridad','caja fuerte','almacenamiento','difusor','porta cepillo'],
        'jardin'          => ['piscina',' cloro','jardin','maceta','poda','rastrillo','cesped','fumigadora','fumigador','aspersor','carretilla de jardin','manguera de jardin','pala ','azadon','machete','desmalezadora','motosierra','bordeadora','inflable','sombrilla','parasol','repelente','insecticida','barbacoa','asador','parrilla','ahumador'],
        'deportes'        => ['bicicleta','mancuerna',' pesa ','trotadora','caminadora','eliptica','balon','gimnasio','patineta','casco de','yoga','cooler','cava'],
        'bebes_juguetes'  => ['juguete','muneca','lego','coche de bebe','cochecito','pañal','panal','biberon','corral','andadera','peluche','rompecabezas','carrito de'],
    ];

    public static function isPhone(?string $brandNorm, ?string $modelNorm): bool
    {
        return PhoneModel::isPhone($brandNorm, $modelNorm);
    }

    /** Devuelve la clave de bucket o null. Los celulares usan PhoneModel. */
    public static function classify(?string $title, ?string $brandNorm = null, ?string $modelNorm = null): ?string
    {
        if (PhoneModel::isPhone($brandNorm, $modelNorm)) {
            return 'celulares';
        }

        $t = ' ' . self::norm((string) $title) . ' ';
        if (trim($t) === '') {
            return null;
        }
        if (str_contains($t, ' celular ') || str_contains($t, 'smartphone')) {
            return 'celulares';
        }

        foreach (self::RULES as $key => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($t, $kw)) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * Pulgadas de pantalla de un TV, del título (NN", NN pulgadas, NN pulg).
     * Devuelve null si no la dice (van al bucket "Otros"). Rango TV: 15–120".
     */
    public static function tvInches(?string $title): ?int
    {
        if ($title === null || $title === '') { return null; }
        // Gallo (OG) guarda las comillas como &quot; → decodificamos entidades primero.
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // "55\"", "55”", "55 pulgadas", "55pulg", "de 55 pulg"
        if (preg_match('/(\d{2,3})\s*(?:"|”|″|\'\'|pulg|pulgadas)/iu', $title, $m)) {
            $n = (int) $m[1];
            if ($n >= 15 && $n <= 120) { return $n; }
        }
        return null;
    }

    /** minúsculas sin acentos, no-alfanumérico → espacio (para matchear con ":", "/", etc.). */
    private static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s); // ":", "/", '"', "&" → espacio
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }
}
