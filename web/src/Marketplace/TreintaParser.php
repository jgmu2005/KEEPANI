<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web\Marketplace;

/**
 * Parser de catálogos Treinta. Dos formatos:
 *  A) catalogo.treinta.co — Next.js: productos en el "flight data" (JSON escapado
 *     una capa). Campos: id(UUID), name, price(num o [min,max]), imageUrl,
 *     isVisible, stock. Precio en córdobas (NIO).
 *  B) tienda.treinta.co — schema.org JSON-LD (Product + offers). Precio string.
 *
 * Sólo lectura pública (sin auth). Devuelve productos normalizados.
 */
final class TreintaParser
{
    /** @return array{store_name:?string, items:array<array{ext_id:string,name:string,price:?float,image_url:string,in_stock:int,currency:string}>} */
    public static function parse(string $html): array
    {
        $items = self::parseFlight($html);
        if (!$items) {
            $items = self::parseJsonLd($html);
        }
        return ['store_name' => self::storeName($html), 'items' => $items];
    }

    /**
     * Formato A: flight data escapado (catalogo.treinta.co). Sirve también para
     * la respuesta JSON del server-action (mismo shape de objeto).
     * Enfoque por TROZOS: corta en cada "id":"UUID" y extrae campos del trozo,
     * así evita el backtracking de un regex gigante y el falso positivo del
     * objeto "tienda" (que no tiene price/isVisible).
     */
    private static function parseFlight(string $html): array
    {
        if (strpos($html, 'isVisible') === false && strpos($html, '\\"isVisible\\"') === false) {
            return [];
        }
        // Deshace UNA capa de escape JSON (\" -> ", \\ -> \, \/ -> /). strtr es de
        // una sola pasada, así no reprocesa lo ya reemplazado.
        $s = strtr($html, ['\\"' => '"', '\\\\' => '\\', '\\/' => '/']);

        if (!preg_match_all('/"id":"[0-9a-f-]{36}"/', $s, $mm, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        $n   = count($mm[0]);
        $len = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $start = $mm[0][$i][1];
            $end   = ($i + 1 < $n) ? $mm[0][$i + 1][1] : min($len, $start + 3000);
            $chunk = substr($s, $start, $end - $start);

            // Debe ser un PRODUCTO: tener price + isVisible dentro de su propio trozo.
            if (!preg_match('/"price":(\[[0-9.,]+\]|[0-9.]+)/', $chunk, $mp)) { continue; }
            if (!preg_match('/"isVisible":([01])/', $chunk, $mv) || (int) $mv[1] !== 1) { continue; }

            preg_match('/"id":"([0-9a-f-]{36})"/', $chunk, $mid);
            preg_match('/"name":"((?:[^"\\\\]|\\\\.)*?)"/', $chunk, $mn);
            preg_match('/"imageUrl":(?:"((?:[^"\\\\]|\\\\.)*?)"|null)/', $chunk, $mi);

            $price = self::priceOf($mp[1]);
            $name  = isset($mn[1]) ? self::unesc($mn[1]) : '';
            if ($name === '' || $price === null) { continue; }

            $out[] = [
                'ext_id'    => $mid[1],
                'name'      => $name,
                'price'     => $price,
                'image_url' => isset($mi[1]) ? self::unesc($mi[1]) : '',
                'in_stock'  => 1,                          // isVisible ⇒ disponible (no confiamos en 'stock')
                'currency'  => 'NIO',
            ];
        }
        return $out;
    }

    /** Formato B: JSON-LD (tienda.treinta.co). */
    private static function parseJsonLd(string $html): array
    {
        $re = '/"name":"((?:[^"\\\\]|\\\\.)*?)","offers":\{"@type":"Offer","price":"([0-9.]+)","priceCurrency":"([A-Z]{3})","availability":"([^"]*)"/';
        if (!preg_match_all($re, $html, $ms, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($ms as $m) {
            $name = self::unesc($m[1]);
            if ($name === '') { continue; }
            $out[] = [
                'ext_id'    => substr(md5($name), 0, 32),
                'name'      => $name,
                'price'     => (float) $m[2],
                'image_url' => '',
                'in_stock'  => str_contains($m[4], 'InStock') ? 1 : 0,
                'currency'  => $m[3] ?: 'NIO',
            ];
        }
        return $out;
    }

    /** Nombre de la tienda desde el JSON-LD (Store / LocalBusiness). */
    private static function storeName(string $html): ?string
    {
        if (preg_match('/"@type":"(?:Store|LocalBusiness)","name":"([^"]+)"/', $html, $m)) {
            return self::unesc($m[1]);
        }
        return null;
    }

    /** price puede ser número o [min,max]; devuelve el mínimo (>0) o null. */
    private static function priceOf(string $raw): ?float
    {
        if ($raw !== '' && $raw[0] === '[') {
            $nums = array_map('floatval', array_filter(explode(',', trim($raw, '[]')), 'strlen'));
            $nums = array_filter($nums, static fn($v) => $v > 0);
            return $nums ? min($nums) : null;
        }
        $v = (float) $raw;
        return $v > 0 ? $v : null;
    }

    /** Deshace escapes que hayan quedado en un valor capturado. */
    private static function unesc(string $s): string
    {
        $s = strtr($s, ['\\"' => '"', '\\\\' => '\\', '\\/' => '/', '\\n' => ' ']);
        $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn($m) => mb_chr((int) hexdec($m[1]), 'UTF-8'), $s) ?? $s;
        return trim($s);
    }
}
