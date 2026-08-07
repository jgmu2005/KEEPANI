<?php
declare(strict_types=1);

/**
 * PÁGINA PÚBLICA POR PRODUCTO (server-rendered, SEO).
 * "Rastreador de precios de {producto} en Nicaragua" — historial de una tienda,
 * precio actual / más bajo / más alto, JSON-LD, y CTA para crear alerta.
 *
 *   /precio.php?id=123           (o URL bonita /precio/123/slug vía .htaccess)
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\DealAnalyzer;
use OjoAlPrecio\Web\Seo;
use OjoAlPrecio\Web\PriceChart;

$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$db   = Db::conn();
$repo = new ProductRepository($db);
$p    = $id > 0 ? $repo->product($id) : null;

$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$usdRate  = (float) ($settings['usd_rate'] ?? 0);
$base     = rtrim(Verification::baseUrl(), '/');

$h   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$slugify = static function (string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim((string) $s, '-'), 0, 70);
};

if (!$p) {
    http_response_code(404);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
    <title>Producto no encontrado · <?= $h($siteName) ?></title>
    <style>body{font-family:system-ui,sans-serif;background:#f1f5f9;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:20px}a{color:#0369a1}</style>
    </head><body><div><h1>😕 No encontramos ese producto</h1>
    <p><a href="<?= $h($base) ?>/index.html">← Volver a <?= $h($siteName) ?></a></p></div></body></html><?php
    exit;
}

$cur     = $p['currency'] ?: 'NIO';
$title   = $p['title'] ?: 'Producto';
$image   = $p['image_url'] ?: '';
$history = $repo->history($id);

$fmt = static function (?float $v) use ($cur): string {
    if ($v === null) return '—';
    return ($cur === 'USD' ? 'US$' : 'C$') . number_format($v, 2);
};
$usd = static function (?float $v) use ($usdRate): string {
    if ($v === null || $usdRate <= 0) return '';
    return '≈ US$' . number_format($v / $usdRate, 2);
};

// Sólo puntos EN STOCK cuentan (evita el precio-centinela de lo agotado).
$live   = array_values(array_filter($history, static fn($r) => $r['in_stock'] && $r['price_final'] !== null));
$prices = array_map(static fn($r) => (float) $r['price_final'], $live);
$last   = end($history) ?: null;
$lastLive = $live ? end($live) : null;

$current   = $lastLive['price_final'] ?? ($last['price_final'] ?? null);
$listPrice = $lastLive['list_price'] ?? null;
$minP      = $prices ? min($prices) : $current;
$maxP      = $prices ? max($prices) : $current;
$inStock   = (bool) ($last['in_stock'] ?? false);
$firstDate = $history[0]['date'] ?? null;
$isLow     = $current !== null && $minP !== null && $current <= $minP * 1.01;
$hasDisc   = $listPrice !== null && $current !== null && $listPrice > $current;

$deal = DealAnalyzer::analyze($current, $listPrice, $prices);
$dealBadge = '';
if ($deal) {
    if ($deal['verdict'] === 'low')  { $dealBadge = ['t' => '🔥 Precio más bajo registrado', 'c' => '#065f46', 'b' => '#d1fae5']; }
    elseif ($deal['verdict'] === 'fake') { $dealBadge = ['t' => '⚠️ Descuento poco fiable', 'c' => '#9a3412', 'b' => '#ffedd5']; }
}

// URL canónica bonita.
$slug     = $slugify($title);
$prettyRel = '/precio/' . $id . ($slug !== '' ? '/' . $slug : '');
$pageUrl  = $base . $prettyRel;

// Serie para el gráfico (una tienda), solo en stock.
$seriesByStore = [];
if (count($live) >= 2) {
    $seriesByStore[$p['store_name']] = array_map(static fn($r) => ['d' => $r['date'], 'p' => (float) $r['price_final']], $live);
}
$chartSvg = PriceChart::svg($seriesByStore, $cur);

// Gate de indexación: sólo indexamos fichas con CONTENIDO REAL — historial
// suficiente para dibujar el gráfico, o que estén en el comparador (≥2 tiendas).
// Las fichas "finas" (1 solo punto) quedan noindex,follow: se crawlean y pasan
// enlace, pero no ensucian la calidad del dominio; maduran solas con los días.
$indexable = (count($live) >= 2) || (($p['group_stores'] ?? 0) >= 2);

// SEO copy.
$brandTxt = $p['brand'] ? $p['brand'] . ' · ' : '';
$metaTitle = 'Rastreador de precios de ' . $title . ' en Nicaragua';
$metaDesc  = 'Historial de precios de ' . $title . ' en ' . $p['store_name'] . ' (Nicaragua). '
           . ($current !== null ? 'Precio actual ' . $fmt($current) . '. ' : '')
           . ($minP !== null && $maxP !== null ? 'Más bajo ' . $fmt($minP) . ', más alto ' . $fmt($maxP) . '. ' : '')
           . 'Creá una alerta y te avisamos cuando baje. ' . $siteName . '.';

$waText = '💰 ' . $title . ($current !== null ? ' — ' . $fmt($current) . ' en ' . $p['store_name'] : '')
        . '. Mirá el historial: ' . $pageUrl;
$waUrl  = 'https://wa.me/?text=' . rawurlencode($waText);

// JSON-LD Product + Offer.
$ld = [
    '@context' => 'https://schema.org',
    '@type'    => 'Product',
    'name'     => $title,
    'url'      => $pageUrl,
];
if ($image)      { $ld['image'] = $image; }
if ($p['brand']) { $ld['brand'] = ['@type' => 'Brand', 'name' => $p['brand']]; }
if ($current !== null) {
    $ld['offers'] = [
        '@type'         => 'Offer',
        'price'         => $current,
        'priceCurrency' => $cur === 'USD' ? 'USD' : 'NIO',
        'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'url'           => $p['url'],
        'seller'        => ['@type' => 'Organization', 'name' => $p['store_name']],
    ];
}
$ldBreadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $pageUrl],
    ],
];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($metaTitle) ?> · <?= $h($siteName) ?></title>
<meta name="description" content="<?= $h($metaDesc) ?>">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:type" content="product">
<meta property="og:title" content="<?= $h($metaTitle) ?>">
<meta property="og:description" content="<?= $h($metaDesc) ?>">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= $h($image) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?= Seo::head($settings, $indexable) ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script type="application/ld+json"><?= json_encode($ldBreadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<style>
  :root{--brand:#0ea5e9;--brand-dk:#0369a1;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--ok:#16a34a;--bad:#dc2626;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f1f5f9;line-height:1.5}
  a{color:var(--brand-dk);text-decoration:none}
  .wrap{max-width:820px;margin:0 auto;padding:20px 16px 60px}
  .crumb{font-size:.82rem;color:var(--muted);padding:6px 0 14px}
  .head{display:flex;gap:18px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;flex-wrap:wrap}
  .head img{width:160px;height:160px;object-fit:contain;background:#f8fafc;border-radius:10px;flex:none}
  .head .info{flex:1;min-width:230px}
  .brand{font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--brand-dk);letter-spacing:.03em}
  h1{font-size:1.35rem;margin:.2rem 0 .5rem;line-height:1.25}
  .now{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .now b{font-size:1.9rem;color:var(--ink)}
  .now b.low{color:var(--ok)}
  .old{color:var(--muted);text-decoration:line-through;font-size:1rem}
  .usd{color:var(--muted);font-size:.82rem}
  .st{font-size:.82rem;font-weight:700;margin-top:4px}
  .st.in{color:var(--ok)} .st.out{color:var(--bad)}
  .deal{display:inline-block;font-size:.78rem;font-weight:800;padding:4px 10px;border-radius:999px;margin-top:8px}
  .cta-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:9px;font-weight:800}
  .btn-primary{background:var(--brand);color:#fff}
  .btn-store{background:#0f172a;color:#fff}
  .btn-wa{background:#25d366;color:#fff}
  .stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px}
  .stats-strip .s{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px}
  .stats-strip .k{font-size:.7rem;text-transform:uppercase;color:var(--muted);letter-spacing:.02em}
  .stats-strip .v{font-size:1.05rem;font-weight:800;margin-top:2px}
  .stats-strip .v.lo{color:var(--ok)} .stats-strip .v.hi{color:var(--bad)}
  h2{font-size:1rem;margin:26px 0 10px}
  .chart{width:100%;height:auto;display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:8px}
  .nodata{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;text-align:center;color:var(--muted)}
  .cmp{display:block;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px;margin-top:16px;font-weight:700;color:var(--brand-dk)}
  .taxnote{margin-top:12px;font-size:.8rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:8px 12px}
  .site{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}
  .site .in{max-width:820px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .site .brand{display:flex;align-items:center;gap:10px;color:#fff}
  .site .logo{font-size:1.7rem}
  .site b{font-size:1.05rem;display:block;line-height:1.15}
  .site small{color:#94a3b8;font-size:.72rem}
  .site .back{color:#7dd3fc;font-weight:600;font-size:.85rem;white-space:nowrap}
  .site-foot{margin-top:34px;padding-top:20px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.84rem}
  .site-foot a{color:var(--brand-dk);font-weight:600}
  @media(max-width:560px){.stats-strip{grid-template-columns:repeat(2,1fr)}.now b{font-size:1.6rem}}
</style>
</head>
<body>
<header class="site"><div class="in">
  <a class="brand" href="<?= $h($base) ?>/index.html"><span class="logo">🔎</span>
    <span><b><?= $h($siteName) ?></b><small>Historial y comparación de precios · Nicaragua 🇳🇮</small></span></a>
  <a class="back" href="<?= $h($base) ?>/index.html">Ver todos los productos →</a>
</div></header>
<div class="wrap">
  <nav class="crumb"><a href="<?= $h($base) ?>/index.html"><?= $h($siteName) ?></a> › Rastreador de precios</nav>

  <div class="head">
    <?php if ($image): ?><img src="<?= $h($image) ?>" alt="<?= $h($title) ?>"><?php endif; ?>
    <div class="info">
      <?php if ($p['brand']): ?><div class="brand"><?= $h($brandTxt) ?><?= $h($p['store_name']) ?></div>
      <?php else: ?><div class="brand"><?= $h($p['store_name']) ?></div><?php endif; ?>
      <h1>Rastreador de precios: <?= $h($title) ?></h1>
      <div class="now">
        <b class="<?= $isLow ? 'low' : '' ?>"><?= $fmt($current) ?></b>
        <?php if ($hasDisc): ?><span class="old"><?= $fmt($listPrice) ?></span><?php endif; ?>
        <?php if ($usd($current) !== ''): ?><span class="usd"><?= $h($usd($current)) ?></span><?php endif; ?>
      </div>
      <div class="st <?= $inStock ? 'in' : 'out' ?>"><?= $inStock ? '● En stock' : '○ Agotado' ?></div>
      <?php if ($dealBadge): ?><span class="deal" style="color:<?= $dealBadge['c'] ?>;background:<?= $dealBadge['b'] ?>"><?= $dealBadge['t'] ?></span><?php endif; ?>
      <?php if (!empty($p['tax_added'])): ?><div class="taxnote">ℹ️ Precio de góndola sin IVA; mostramos <b>IVA estimado (+15%)</b> para comparar parejo.</div><?php endif; ?>
      <div class="cta-row">
        <a class="btn btn-primary" href="<?= $h($base) ?>/index.html?p=<?= $id ?>">🔔 Avisame cuando baje</a>
        <a class="btn btn-store" href="<?= $h($p['url']) ?>" target="_blank" rel="noopener">Ver en <?= $h($p['store_name']) ?> ↗</a>
        <a class="btn btn-wa" href="<?= $h($waUrl) ?>" target="_blank" rel="noopener">📲 Compartir</a>
      </div>
    </div>
  </div>

  <?php if (!empty($p['group_slug']) && ($p['group_stores'] ?? 0) >= 2): ?>
    <a class="cmp" href="<?= $h($base) ?>/producto.php?slug=<?= $h(rawurlencode((string) $p['group_slug'])) ?>">⚖️ Este producto está en <?= (int) $p['group_stores'] ?> tiendas — comparalas y ahorrá →</a>
  <?php endif; ?>

  <div class="stats-strip">
    <div class="s"><div class="k">Precio actual</div><div class="v <?= $isLow ? 'lo' : '' ?>"><?= $fmt($current) ?></div></div>
    <div class="s"><div class="k">Más bajo</div><div class="v lo"><?= $fmt($minP) ?></div></div>
    <div class="s"><div class="k">Más alto</div><div class="v hi"><?= $fmt($maxP) ?></div></div>
    <div class="s"><div class="k">Rastreando desde</div><div class="v"><?= $firstDate ? $h(date('d/m/Y', strtotime($firstDate))) : '—' ?></div></div>
  </div>

  <h2>Historial de precios en <?= $h($p['store_name']) ?></h2>
  <?php if ($chartSvg !== ''): ?>
    <?= $chartSvg ?>
  <?php else: ?>
    <div class="nodata">📅 Necesitamos algunos días más de datos para dibujar la tendencia de <?= $h($title) ?>. Volvé pronto.</div>
  <?php endif; ?>

  <footer class="site-foot">
    <p>Precios referenciales tomados de <?= $h($p['store_name']) ?>. Verificá el precio final antes de comprar.</p>
    <p><a href="<?= $h($base) ?>/index.html">Inicio</a> · <a href="<?= $h($base) ?>/ayuda.html">Ayuda</a> · <a href="<?= $h($base) ?>/terminos.html">Términos y privacidad</a> · <?= $h($siteName) ?> 🇳🇮</p>
  </footer>
</div>
</body>
</html>
