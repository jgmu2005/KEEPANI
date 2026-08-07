<?php
declare(strict_types=1);

namespace OjoAlPrecio\Web;

/**
 * Etiquetas de <head> para SEO/analytics, tomadas de los ajustes del sitio.
 * Se usa en las páginas server-rendered (producto.php, precio.php) — donde
 * Google realmente indexa — y su equivalente JS en index.html (applySettings).
 */
final class Seo
{
    /**
     * Bloque para el <head>: robots, verificación de Search Console y GA4.
     * @param array $settings mapa de Settings::all()
     * @param bool  $indexablePage si esta página en particular debe indexarse
     */
    public static function head(array $settings, bool $indexablePage = true): string
    {
        $h  = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $siteOn = ($settings['seo_indexable'] ?? '1') !== '0'; // switch global
        $index  = $siteOn && $indexablePage;

        $out  = '<meta name="robots" content="' . ($index ? 'index,follow,max-image-preview:large' : 'noindex,follow') . '">' . "\n";

        $gsc = trim((string) ($settings['gsc_verification'] ?? ''));
        if ($gsc !== '') {
            $out .= '<meta name="google-site-verification" content="' . $h($gsc) . '">' . "\n";
        }

        $ga = trim((string) ($settings['ga_measurement_id'] ?? ''));
        if ($ga !== '' && preg_match('/^G-[A-Z0-9]+$/i', $ga)) {
            $out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $h($ga) . '"></script>' . "\n"
                  . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
                  . 'gtag("js",new Date());gtag("config","' . $h($ga) . '");</script>' . "\n";
        }
        return $out;
    }
}
