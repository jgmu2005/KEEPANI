<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Gráfico SVG de líneas del precio por tienda (server-rendered, sin JS).
 * Compartido por producto.php (multi-tienda) y precio.php (una tienda).
 * $seriesByStore: [nombreTienda => [['d'=>'Y-m-d','p'=>float], ...]]
 */
final class PriceChart
{
    public static function svg(array $seriesByStore, string $cur): string
    {
        $colors = ['#0ea5e9', '#16a34a', '#f59e0b', '#8b5cf6', '#dc2626', '#0891b2'];
        $W = 720; $H = 300; $Lp = 58; $Rp = 14; $Tp = 12; $Bp = 34;
        $pw = $W - $Lp - $Rp; $ph = $H - $Tp - $Bp;

        $allTs = []; $allP = [];
        foreach ($seriesByStore as $s) {
            foreach ($s as $pt) { $allTs[] = strtotime($pt['d']); $allP[] = (float) $pt['p']; }
        }
        if (count($allP) < 2) { return ''; }
        $minTs = min($allTs); $maxTs = max($allTs); $minP = min($allP); $maxP = max($allP);
        if ($maxTs == $minTs) { $maxTs = $minTs + 86400; }
        if ($maxP == $minP)  { $maxP  = $minP + 1; }

        $x = static fn($ts): float => $Lp + ($ts - $minTs) / ($maxTs - $minTs) * $pw;
        $y = static fn($p): float  => $Tp + (1 - ($p - $minP) / ($maxP - $minP)) * $ph;
        $lbl = static fn($v): string => ($cur === 'USD' ? 'US$' : 'C$') . number_format($v, 0);

        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="chart" role="img" aria-label="Historial de precios">';
        for ($i = 0; $i <= 3; $i++) {
            $val = $minP + ($maxP - $minP) * $i / 3; $yy = round($y($val), 1);
            $svg .= '<line x1="' . $Lp . '" y1="' . $yy . '" x2="' . ($W - $Rp) . '" y2="' . $yy . '" stroke="#e2e8f0"/>';
            $svg .= '<text x="' . ($Lp - 6) . '" y="' . ($yy + 3) . '" text-anchor="end" font-size="10" fill="#94a3b8">' . $lbl($val) . '</text>';
        }
        $svg .= '<text x="' . round($x($minTs), 1) . '" y="' . ($H - 12) . '" font-size="10" fill="#94a3b8">' . date('d/m', $minTs) . '</text>';
        $svg .= '<text x="' . round($x($maxTs), 1) . '" y="' . ($H - 12) . '" text-anchor="end" font-size="10" fill="#94a3b8">' . date('d/m', $maxTs) . '</text>';

        $ci = 0;
        foreach ($seriesByStore as $s) {
            $col = $colors[$ci % count($colors)]; $ci++;
            $pts = [];
            foreach ($s as $pt) { $pts[] = round($x(strtotime($pt['d'])), 1) . ',' . round($y((float) $pt['p']), 1); }
            if (count($pts) === 1) {
                [$px, $py] = explode(',', $pts[0]);
                $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3.5" fill="' . $col . '"/>';
            } else {
                $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            }
        }
        $svg .= '</svg>';

        // Leyenda solo si hay más de una tienda.
        if (count($seriesByStore) > 1) {
            $ci = 0; $leg = '<div class="legend">';
            foreach ($seriesByStore as $store => $s) {
                $col = $colors[$ci % count($colors)]; $ci++;
                $leg .= '<span><i style="background:' . $col . '"></i>' . htmlspecialchars((string) $store, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $leg .= '</div>';
            $svg .= $leg;
        }
        return $svg;
    }
}
