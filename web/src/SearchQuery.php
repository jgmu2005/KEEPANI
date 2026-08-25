<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Búsqueda por PALABRAS SUELTAS, orden-independiente: parte el término en
 * palabras y exige que TODAS aparezcan (en cualquier orden) en la(s) columna(s).
 * Ej.: "cubitt audifono" matchea "Audífono Cubitt In-Ear …".
 */
final class SearchQuery
{
    /** Palabras del término: deduplicadas (case-insensitive), tope razonable. */
    public static function words(string $q, int $max = 6): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $words = [];
        foreach (preg_split('/\s+/', $q) ?: [] as $w) {
            $w = trim($w);
            if ($w === '') {
                continue;
            }
            $words[mb_strtolower($w, 'UTF-8')] = $w; // dedupe sin importar mayúsculas
            if (count($words) >= $max) {
                break;
            }
        }
        return array_values($words);
    }

    /**
     * Condición SQL (AND de LIKEs, una por palabra) + sus params.
     * Cada palabra debe aparecer en AL MENOS UNA de las columnas dadas.
     *
     * @param string[] $columns  columnas donde buscar (OR entre ellas por palabra)
     * @param string   $prefix   prefijo de placeholder (ej. ':q' → :q0, :q1…)
     * @return array{0:string,1:array<string,string>}  ['(...) AND (...)', [':q0'=>'%a%', …]]
     */
    public static function like(string $q, array $columns, string $prefix = ':q'): array
    {
        $words = self::words($q);
        if (!$words || !$columns) {
            return ['', []];
        }
        $conds  = [];
        $params = [];
        foreach ($words as $i => $w) {
            $ph  = $prefix . $i;
            $ors = [];
            foreach ($columns as $c) {
                $ors[] = "$c LIKE $ph";
            }
            $conds[]      = '(' . implode(' OR ', $ors) . ')';
            $params[$ph]  = '%' . $w . '%';
        }
        return [implode(' AND ', $conds), $params];
    }
}
