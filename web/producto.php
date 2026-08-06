<?php
declare(strict_types=1);

/**
 * PÁGINA PÚBLICA POR PRODUCTO (comparador cross-store) — server-rendered.
 * Es la superficie de la fase #3: SEO (HTML + JSON-LD), comparador de precios
 * entre tiendas y botón de compartir por WhatsApp.
 *
 *   /producto.php?slug=secadora-remington-...-a3f9c1
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$db   = Db::conn();
$repo = new ProductRepository($db);
$data = $slug !== '' ? $repo->groupBySlug($slug) : null;

$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$usdRate  = (float) ($settings['usd_rate'] ?? 0);
$base     = rtrim(Verification::baseUrl(), '/');

$h    = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmt  = static function (?float $v, string $cur): string {
    if ($v === null) return '—';
    return ($cur === 'USD' ? 'US$' : 'C$') . number_format($v, 2);
};
$usd  = static function (?float $v) use ($usdRate): string {
    if ($v === null || $usdRate <= 0) return '';
    return '≈ US$' . number_format($v / $usdRate, 2);
};

if (!$data || !$data['offers']) {
    http_response_code(404);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Producto no encontrado · <?= $h($siteName) ?></title>
    <style>body{font-family:system-ui,sans-serif;background:#f1f5f9;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:20px}a{color:#0369a1}</style>
    </head><body><div><h1>😕 No encontramos ese producto</h1>
    <p>Puede que todavía no lo estemos comparando entre tiendas.</p>
    <p><a href="index.html">← Volver a <?= $h($siteName) ?></a></p></div></body></html><?php
    exit;
}

$g       = $data['group'];
$offers  = $data['offers'];
$priced  = array_values(array_filter($offers, static fn($o) => $o['price_final'] !== null));
$low     = $priced ? min(array_map(static fn($o) => $o['price_final'], $priced)) : null;
$high    = $priced ? max(array_map(static fn($o) => $o['price_final'], $priced)) : null;
$cur     = $offers[0]['currency'] ?? 'NIO';
$title   = $g['canonical_title'] ?: 'Producto';
$image   = $g['image_url'] ?: ($offers[0]['image_url'] ?? '');
$cheapest = $priced[0] ?? null; // ya vienen ordenadas por precio asc
$pageUrl = $base . '/producto.php?slug=' . rawurlencode((string) $g['slug']);

$savingTxt = ($low !== null && $high !== null && $high > $low)
    ? ' Ahorrás hasta ' . $fmt($high - $low, $cur) . '.'
    : '';
$desc = 'Compará el precio de "' . $title . '" en ' . $g['store_count'] . ' tienda'
      . ($g['store_count'] === 1 ? '' : 's') . ' de Nicaragua'
      . ($low !== null ? ', desde ' . $fmt($low, $cur) . '.' : '.') . $savingTxt
      . ' Historial y ofertas en ' . $siteName . '.';

// WhatsApp
$waText = '💰 ' . $title . ($low !== null ? ' — desde ' . $fmt($low, $cur) : '')
        . ' en ' . $g['store_count'] . ' tiendas. Compará acá: ' . $pageUrl;
$waUrl  = 'https://wa.me/?text=' . rawurlencode($waText);

// JSON-LD (schema.org Product + AggregateOffer)
$ld = [
    '@context' => 'https://schema.org',
    '@type'    => 'Product',
    'name'     => $title,
    'url'      => $pageUrl,
];
if ($image)       { $ld['image'] = $image; }
if ($g['brand'])  { $ld['brand'] = ['@type' => 'Brand', 'name' => $g['brand']]; }
if ($priced) {
    $ld['offers'] = [
        '@type'         => 'AggregateOffer',
        'priceCurrency' => $cur === 'USD' ? 'USD' : 'NIO',
        'lowPrice'      => $low,
        'highPrice'     => $high,
        'offerCount'    => count($priced),
        'offers'        => array_map(static function ($o) use ($cur) {
            return [
                '@type'         => 'Offer',
                'price'         => $o['price_final'],
                'priceCurrency' => $cur === 'USD' ? 'USD' : 'NIO',
                'availability'  => $o['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url'           => $o['url'],
                'seller'        => ['@type' => 'Organization', 'name' => $o['store_name']],
            ];
        }, $priced),
    ];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?> — precio en <?= (int) $g['store_count'] ?> tiendas · <?= $h($siteName) ?></title>
<meta name="description" content="<?= $h($desc) ?>">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:type" content="product">
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= $h($image) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<style>
  :root{--brand:#0ea5e9;--brand-dk:#0369a1;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--ok:#16a34a;--bad:#dc2626;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f1f5f9;line-height:1.5}
  a{color:var(--brand-dk);text-decoration:none}
  .wrap{max-width:820px;margin:0 auto;padding:20px 16px 60px}
  .top{display:flex;align-items:center;gap:10px;padding:8px 0 18px}
  .top a{font-weight:600}
  .head{display:flex;gap:18px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;flex-wrap:wrap}
  .head img{width:150px;height:150px;object-fit:contain;background:#f8fafc;border-radius:10px;flex:none}
  .head .info{flex:1;min-width:220px}
  .brand{font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--brand-dk);letter-spacing:.03em}
  h1{font-size:1.3rem;margin:.2rem 0 .6rem}
  .best{font-size:1rem;color:var(--muted)}
  .best b{color:var(--ok);font-size:1.5rem}
  .share{display:inline-flex;align-items:center;gap:8px;margin-top:14px;background:#25d366;color:#fff;padding:10px 16px;border-radius:9px;font-weight:700}
  h2{font-size:1rem;margin:26px 0 10px}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--line)}
  th{font-size:.75rem;text-transform:uppercase;color:var(--muted);letter-spacing:.03em}
  tr:last-child td{border-bottom:0}
  .price{font-weight:800;font-size:1.05rem;white-space:nowrap}
  .old{color:var(--muted);text-decoration:line-through;font-size:.82rem;font-weight:500;margin-left:6px}
  .usd{color:var(--muted);font-size:.72rem}
  .cheap{background:#f0fdf4}
  .tag{display:inline-block;font-size:.64rem;font-weight:800;color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:999px;margin-left:6px}
  .st{font-size:.78rem;font-weight:600}
  .st.in{color:var(--ok)} .st.out{color:var(--bad)}
  .go{background:var(--brand);color:#fff;padding:8px 13px;border-radius:8px;font-weight:700;white-space:nowrap}
  .foot{margin-top:26px;color:var(--muted);font-size:.82rem;text-align:center}
  @media(max-width:560px){.usd,th.h-usd,td.c-usd{display:none}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="index.html">← <?= $h($siteName) ?></a></div>

  <div class="head">
    <?php if ($image): ?><img src="<?= $h($image) ?>" alt="<?= $h($title) ?>"><?php endif; ?>
    <div class="info">
      <?php if ($g['brand']): ?><div class="brand"><?= $h($g['brand']) ?></div><?php endif; ?>
      <h1><?= $h($title) ?></h1>
      <?php if ($low !== null): ?>
        <div class="best">Desde <b><?= $fmt($low, $cur) ?></b>
          <?php if ($cheapest): ?>en <?= $h($cheapest['store_name']) ?><?php endif; ?>
          · en <?= (int) $g['store_count'] ?> tienda<?= $g['store_count'] === 1 ? '' : 's' ?>
        </div>
      <?php endif; ?>
      <a class="share" href="<?= $h($waUrl) ?>" target="_blank" rel="noopener">📲 Compartir por WhatsApp</a>
    </div>
  </div>

  <h2>Comparación de precios</h2>
  <table>
    <thead><tr>
      <th>Tienda</th><th>Precio</th><th class="h-usd c-usd">USD</th><th>Estado</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($offers as $i => $o):
        $isCheap = $cheapest && $o['price_final'] !== null && $o['price_final'] === $cheapest['price_final'];
        $hasDisc = $o['list_price'] !== null && $o['price_final'] !== null && $o['list_price'] > $o['price_final'];
    ?>
      <tr class="<?= $isCheap ? 'cheap' : '' ?>">
        <td><b><?= $h($o['store_name']) ?></b><?= $isCheap ? '<span class="tag">💚 más barato</span>' : '' ?></td>
        <td class="price"><?= $fmt($o['price_final'], $o['currency']) ?><?php if ($hasDisc): ?><span class="old"><?= $fmt($o['list_price'], $o['currency']) ?></span><?php endif; ?></td>
        <td class="usd c-usd"><?= $h($usd($o['price_final'])) ?></td>
        <td><span class="st <?= $o['in_stock'] ? 'in' : 'out' ?>"><?= $o['in_stock'] ? '● En stock' : '○ Agotado' ?></span></td>
        <td><a class="go" href="<?= $h($o['url']) ?>" target="_blank" rel="noopener">Ver ↗</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="foot">Precios referenciales, tomados de cada tienda. Verificá el precio final antes de comprar.<br>
    Comparación de <?= $h($siteName) ?> 🇳🇮</p>
</div>
</body>
</html>
