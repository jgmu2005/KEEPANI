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
use OjoAlPrecio\Web\Seo;
use OjoAlPrecio\Web\PriceChart;
use OjoAlPrecio\Web\Auth;

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$db   = Db::conn();
$repo = new ProductRepository($db);
$data = $slug !== '' ? $repo->groupBySlug($slug) : null;

// Panel de edición manual: sólo para el admin. Sólo consultamos la sesión si ya
// hay cookie — así un visitante anónimo (o Googlebot) no arranca sesión en esta
// página SEO ni recibe Set-Cookie.
$isAdmin = false;
if (isset($_COOKIE[session_name()])) {
    $me = Auth::currentUser($db);
    $isAdmin = $me !== null && !empty($me['is_admin']);
}

$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$usdRate  = (float) ($settings['usd_rate'] ?? 0);
$base     = rtrim(Verification::baseUrl(), '/');

$normUrl    = static function (?string $u): string {
    $u = trim((string) $u);
    if ($u === '') return '';
    return preg_match('~^https?://~i', $u) ? $u : 'https://' . $u;
};
$kofi       = $normUrl($settings['donate_kofi'] ?? '');
$paypal     = $normUrl($settings['donate_paypal'] ?? '');
$footerNote = trim((string) ($settings['footer_note'] ?? ''));

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
// Sólo las ofertas EN STOCK entran a la comparación: las agotadas traen un
// precio-centinela (ej. Siman C$10,000,000) que inflaba el ahorro y el máximo.
$priced  = array_values(array_filter($offers, static fn($o) => $o['in_stock'] && $o['price_final'] !== null));
$soldOut = array_values(array_filter($offers, static fn($o) => !$o['in_stock']));
// Fallback: si TODO está agotado, mostramos igual las ofertas con precio.
if (!$priced) {
    $priced  = array_values(array_filter($offers, static fn($o) => $o['price_final'] !== null));
    $soldOut = [];
}
$low     = $priced ? min(array_map(static fn($o) => $o['price_final'], $priced)) : null;
$high    = $priced ? max(array_map(static fn($o) => $o['price_final'], $priced)) : null;
$cur     = $priced[0]['currency'] ?? ($offers[0]['currency'] ?? 'NIO');
$title   = $g['canonical_title'] ?: 'Producto';
$image   = $g['image_url'] ?: ($offers[0]['image_url'] ?? '');
$cheapest = $priced[0] ?? null; // ya vienen ordenadas por precio asc
$trackId  = (int) ($cheapest['id'] ?? ($offers[0]['id'] ?? 0)); // para deep-link a la ficha
// # de tiendas con stock (distintas) — no el conteo bruto del grupo.
$storeCount = count(array_unique(array_map(static fn($o) => $o['store'], $priced)));
$pageUrl = $base . '/producto.php?slug=' . rawurlencode((string) $g['slug']);

// ¿Es el mismo producto en ≥2 tiendas de UNICOMER? (Tropigas, El Gallo, RadioShack,
// La Curacao comparten catálogo; Unicomer les pone precio distinto y los rota).
$unicomerSlugs = ['tropigas', 'gallo', 'radioshack', 'lacuracao'];
$unicomerHere  = array_values(array_unique(array_filter(
    array_map(static fn($o) => $o['store'], $priced),
    static fn($slug) => in_array($slug, $unicomerSlugs, true)
)));
$sameChain = count($unicomerHere) >= 2;

// Series por tienda para el gráfico + mínimos/máximos históricos.
$ids        = array_map(static fn($o) => $o['id'], $priced);
$seriesById = $repo->priceSeries($ids, 90);
$seriesByStore = [];
$allPts = [];
foreach ($priced as $o) {
    $s = $seriesById[$o['id']] ?? [];
    if ($s) {
        $seriesByStore[$o['store_name']] = $s;
        foreach ($s as $pt) { $allPts[] = (float) $pt['p']; }
    }
}
$histMin  = $allPts ? min($allPts) : $low;
$histMax  = $allPts ? max($allPts) : $high;
$chartSvg = PriceChart::svg($seriesByStore, $cur);

$savingTxt = ($low !== null && $high !== null && $high > $low)
    ? ' Ahorrás hasta ' . $fmt($high - $low, $cur) . '.'
    : '';
$desc = 'Compará el precio de "' . $title . '" en ' . $storeCount . ' tienda'
      . ($storeCount === 1 ? '' : 's') . ' de Nicaragua'
      . ($low !== null ? ', desde ' . $fmt($low, $cur) . '.' : '.') . $savingTxt
      . ' Historial y ofertas en ' . $siteName . '.';

// Frase "answer-shaped": resumen factual citable por AI y Google ("¿dónde está
// más barato X en Nicaragua?").
$priciest = $priced ? $priced[count($priced) - 1] : null;
$diffPct  = ($low !== null && $high !== null && $low > 0 && $high > $low) ? (int) round(($high - $low) / $low * 100) : 0;
$answer = ($low !== null && $cheapest)
    ? ('En Nicaragua, ' . $title . ' se consigue desde ' . $fmt($low, $cur) . ' en ' . $cheapest['store_name']
        . ($diffPct > 0 && $priciest ? ' y hasta ' . $fmt($high, $cur) . ' en ' . $priciest['store_name'] . ' — una diferencia del ' . $diffPct . '%' : '')
        . '. El precio más bajo es ' . $fmt($low, $cur) . ' en ' . $cheapest['store_name'] . '.'
        . ($sameChain ? ' Varias de estas tiendas son de la misma empresa (Unicomer): el mismo producto a distinto precio según la tienda.' : ''))
    : '';

// WhatsApp
$waText = '💰 ' . $title . ($low !== null ? ' — desde ' . $fmt($low, $cur) : '')
        . ' en ' . $storeCount . ' tiendas. Compará acá: ' . $pageUrl;
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
        'priceValidUntil' => date('Y-m-d', strtotime('+2 days')), // precios se refrescan a diario
        'offers'        => array_map(static function ($o) use ($cur) {
            return [
                '@type'         => 'Offer',
                'price'         => $o['price_final'],
                'priceCurrency' => $cur === 'USD' ? 'USD' : 'NIO',
                'availability'  => $o['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
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
<title><?= $h($title) ?> — precio en <?= (int) $storeCount ?> tiendas · <?= $h($siteName) ?></title>
<meta name="description" content="<?= $h($desc) ?>">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:type" content="product">
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= $h($image) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?= Seo::head($settings, true) ?>
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
  .taxest{display:block;color:var(--muted);font-size:.66rem;font-weight:600}
  .taxnote{margin-top:10px;font-size:.8rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:8px 12px}
  .samechain{margin:2px 0 14px;font-size:.9rem;line-height:1.5;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:11px 14px}
  .admin-edit{margin-top:28px;background:var(--card);border:2px dashed #93c5fd;border-radius:14px;padding:18px 18px 20px}
  .admin-edit h2{margin:0 0 4px}
  .admin-badge{font-size:.6rem;font-weight:800;color:#1e40af;background:#dbeafe;padding:3px 8px;border-radius:999px;vertical-align:middle;margin-left:6px}
  .ae-help{font-size:.84rem;color:var(--muted);margin:0 0 14px;line-height:1.5}
  .ae-h3{font-size:.9rem;margin:16px 0 8px}
  .ae-row{display:flex;align-items:center;gap:10px;padding:7px 4px;border-bottom:1px solid var(--line)}
  .ae-row img{width:38px;height:38px;object-fit:contain;background:#f8fafc;border-radius:6px;flex:none}
  .ae-info{flex:1;min-width:0;font-size:.85rem;overflow:hidden;text-overflow:ellipsis}
  .ae-ingroup{color:#b45309;font-weight:700}
  .admin-edit button{cursor:pointer;border:0;border-radius:8px;font-weight:700;font-size:.8rem;padding:6px 12px;flex:none}
  .ae-remove{background:#fee2e2;color:#b91c1c}
  .ae-add{background:#dcfce7;color:#166534}
  .ae-unlock{background:#e0e7ff;color:#3730a3}
  .ae-lock{font-size:.66rem;font-weight:800;color:#3730a3;background:#e0e7ff;padding:2px 7px;border-radius:999px;margin-left:6px;white-space:nowrap}
  .admin-edit input[type=search]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:.9rem;background:var(--card);color:inherit}
  #aeResults{margin-top:4px}
  .ae-msg{margin-top:10px;font-size:.85rem;color:#b91c1c}
  .usd{color:var(--muted);font-size:.72rem}
  .cheap{background:#f0fdf4}
  .tag{display:inline-block;font-size:.64rem;font-weight:800;color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:999px;margin-left:6px}
  .seller{color:var(--muted);font-size:.72rem}
  .answer{background:#f0f9ff;border:1px solid #bae6fd;border-left:4px solid var(--brand);border-radius:10px;padding:14px 16px;margin:0 0 18px;font-size:.95rem;line-height:1.55;color:var(--ink)}
  .st{font-size:.78rem;font-weight:600}
  .st.in{color:var(--ok)} .st.out{color:var(--bad)}
  .go{background:var(--brand);color:#fff;padding:8px 13px;border-radius:8px;font-weight:700;white-space:nowrap}
  .foot{margin-top:26px;color:var(--muted);font-size:.82rem;text-align:center}
  .cta-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .btn-primary{display:inline-flex;align-items:center;gap:8px;background:var(--brand);color:#fff;padding:11px 18px;border-radius:9px;font-weight:800}
  .site-foot{margin-top:34px;padding-top:20px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.84rem}
  .site-foot .donate{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;align-items:center;margin:12px 0}
  .site-foot .donate a{background:#1e293b;color:#e2e8f0;padding:8px 14px;border-radius:8px;font-weight:600}
  .site-foot .legal{margin:12px 0}
  .site-foot .legal a{color:var(--brand-dk);font-weight:600}
  /* header con marca */
  .site{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}
  .site .in{max-width:820px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .brand{display:flex;align-items:center;gap:10px;color:#fff}
  .brand .logo{font-size:1.7rem;line-height:1}
  .brand b{font-size:1.05rem;display:block;line-height:1.15}
  .brand small{color:#94a3b8;font-size:.72rem}
  .site .back{color:#7dd3fc;font-weight:600;font-size:.85rem;white-space:nowrap}
  /* franja de stats */
  .stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}
  .stats-strip .s{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px}
  .stats-strip .k{font-size:.7rem;text-transform:uppercase;color:var(--muted);letter-spacing:.02em}
  .stats-strip .v{font-size:1.1rem;font-weight:800;margin-top:2px}
  .stats-strip .v.lo{color:var(--ok)} .stats-strip .v.hi{color:var(--bad)}
  /* gráfico */
  .chart{width:100%;height:auto;display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:8px}
  .legend{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;margin-top:10px;font-size:.82rem;color:var(--muted)}
  .legend span{display:inline-flex;align-items:center;gap:6px}
  .legend i{width:14px;height:3px;border-radius:2px;display:inline-block}
  .nodata{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;text-align:center;color:var(--muted)}
  @media(max-width:560px){.usd,th.h-usd,td.c-usd{display:none}.stats-strip{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<header class="site"><div class="in">
  <a class="brand" href="index.html">
    <span class="logo">🔎</span>
    <span><b><?= $h($siteName) ?></b><small>Historial y comparación de precios · Nicaragua 🇳🇮</small></span>
  </a>
  <a class="back" href="index.html">Ver todos los productos →</a>
</div></header>
<div class="wrap">

  <div class="head">
    <?php if ($image): ?><img src="<?= $h($image) ?>" alt="<?= $h($title) ?>"><?php endif; ?>
    <div class="info">
      <?php if ($g['brand']): ?><div class="brand"><?= $h($g['brand']) ?></div><?php endif; ?>
      <h1><?= $h($title) ?></h1>
      <?php if ($low !== null): ?>
        <div class="best">Desde <b><?= $fmt($low, $cur) ?></b>
          <?php if ($cheapest): ?>en <?= $h($cheapest['store_name']) ?><?php endif; ?>
          · en <?= (int) $storeCount ?> tienda<?= $storeCount === 1 ? '' : 's' ?>
        </div>
      <?php endif; ?>
      <div class="cta-row">
        <?php if ($trackId > 0): ?>
          <a class="btn-primary" href="index.html?p=<?= $trackId ?>">🔔 Trackear este producto</a>
        <?php endif; ?>
        <a class="share" href="<?= $h($waUrl) ?>" target="_blank" rel="noopener">📲 Compartir</a>
      </div>
    </div>
  </div>

  <?php if ($answer !== ''): ?><p class="answer"><?= $h($answer) ?></p><?php endif; ?>

  <h2>Comparación de precios</h2>
  <?php if ($sameChain): ?>
    <div class="samechain">
      ⚠️ <b>Es la misma empresa.</b> Almacenes Tropigas, El Gallo más Gallo, RadioShack y La Curacao son la <b>misma cadena (Unicomer)</b>: el mismo producto con precio distinto según la tienda. Comprá en la más barata 👇
    </div>
  <?php endif; ?>
  <table>
    <thead><tr>
      <th>Tienda</th><th>Precio</th><th class="h-usd c-usd">USD</th><th>Estado</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($priced as $i => $o):
        $isCheap = $cheapest && $o['price_final'] !== null && $o['price_final'] === $cheapest['price_final'];
        $hasDisc = $o['list_price'] !== null && $o['price_final'] !== null && $o['list_price'] > $o['price_final'];
    ?>
      <tr class="<?= $isCheap ? 'cheap' : '' ?>">
        <td><b><?= $h($o['store_name']) ?></b><?= $isCheap ? '<span class="tag">💚 más barato</span>' : '' ?><?php if (!empty($o['seller'])): ?><br><small class="seller">🏬 vendido por <?= $h($o['seller']) ?></small><?php endif; ?></td>
        <td class="price"><?= $fmt($o['price_final'], $o['currency']) ?><?php if ($hasDisc): ?><span class="old"><?= $fmt($o['list_price'], $o['currency']) ?></span><?php endif; ?><?php if (!empty($o['tax_added'])): ?><small class="taxest">IVA estimado incluido</small><?php endif; ?></td>
        <td class="usd c-usd"><?= $h($usd($o['price_final'])) ?></td>
        <td><span class="st in">● En stock</span></td>
        <td><a class="go" href="<?= $h($o['url']) ?>" target="_blank" rel="noopener">Ver ↗</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($soldOut): ?>
    <div class="taxnote">○ Sin stock ahora mismo en: <b><?= $h(implode(', ', array_unique(array_map(static fn($o) => $o['store_name'], $soldOut)))) ?></b>. No se cuentan en la comparación hasta que vuelvan a estar disponibles.</div>
  <?php endif; ?>
  <?php if (array_filter($priced, static fn($o) => !empty($o['tax_added']))): ?>
    <div class="taxnote">ℹ️ En tiendas tipo club (ej. PriceSmart) el precio de góndola se muestra <b>sin IVA</b> y el impuesto se agrega en la caja. Acá lo mostramos <b>con IVA estimado (+15%)</b> para comparar de forma justa con el resto.</div>
  <?php endif; ?>

  <div class="stats-strip">
    <div class="s"><div class="k">Más barato ahora</div><div class="v lo"><?= $fmt($low, $cur) ?></div></div>
    <div class="s"><div class="k">Mínimo histórico</div><div class="v"><?= $fmt($histMin, $cur) ?></div></div>
    <div class="s"><div class="k">Máximo histórico</div><div class="v hi"><?= $fmt($histMax, $cur) ?></div></div>
    <div class="s"><div class="k">Tiendas</div><div class="v"><?= (int) $storeCount ?></div></div>
  </div>

  <h2>Historial de precios por tienda</h2>
  <?php if ($chartSvg !== ''): ?>
    <?= $chartSvg ?>
  <?php else: ?>
    <div class="nodata">📅 Necesitamos algunos días más de datos para dibujar la tendencia. Volvé pronto.</div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <section class="admin-edit" id="adminEdit" data-slug="<?= $h($g['slug']) ?>">
    <h2>🔧 Editar comparativo <span class="admin-badge">solo admin</span></h2>
    <p class="ae-help">Agregá o quitá productos de esta comparación. Los cambios quedan <b>fijos</b> (el matcher automático ya no los toca). Si agregás un producto que ya está en otro comparativo, podés <b>traer todos</b> sus productos.</p>

    <h3 class="ae-h3">En este comparativo (<?= count($offers) ?>)</h3>
    <div id="aeMembers">
      <?php foreach ($offers as $o): ?>
        <div class="ae-row">
          <?php if (!empty($o['image_url'])): ?><img src="<?= $h($o['image_url']) ?>" alt=""><?php endif; ?>
          <div class="ae-info"><b><?= $h($o['store_name']) ?></b> · <?= $h($o['title']) ?><?php if (!empty($o['locked'])): ?> <span class="ae-lock">🔒 manual</span><?php endif; ?></div>
          <?php if (!empty($o['locked'])): ?><button class="ae-unlock" data-id="<?= (int) $o['id'] ?>" title="Volver al modo automático (el matcher vuelve a manejarlo)">🔓 Auto</button><?php endif; ?>
          <button class="ae-remove" data-id="<?= (int) $o['id'] ?>">Quitar ✕</button>
        </div>
      <?php endforeach; ?>
    </div>

    <h3 class="ae-h3">Agregar producto</h3>
    <input type="search" id="aeSearch" placeholder="Buscar por nombre… (mín. 2 letras)" autocomplete="off">
    <div id="aeResults"></div>
    <div id="aeMsg" class="ae-msg"></div>
  </section>
  <?php endif; ?>

  <footer class="site-foot">
    <?php if ($footerNote !== ''): ?><p><?= $h($footerNote) ?></p><?php endif; ?>
    <?php if ($kofi !== '' || $paypal !== ''): ?>
      <div class="donate">
        <span>¿Te sirve? Ayudanos a mantenerlo:</span>
        <?php if ($kofi   !== ''): ?><a href="<?= $h($kofi) ?>" target="_blank" rel="noopener">☕ Doná (Ko-fi)</a><?php endif; ?>
        <?php if ($paypal !== ''): ?><a href="<?= $h($paypal) ?>" target="_blank" rel="noopener">💳 PayPal</a><?php endif; ?>
      </div>
    <?php endif; ?>
    <p style="margin:12px 0"><a href="https://whatsapp.com/channel/0029Vb8rtBl35fLzbo8lsb2b" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;padding:9px 18px;border-radius:999px;text-decoration:none;font-weight:700">🟢 Seguinos en WhatsApp — ofertas reales del día</a></p>
    <p class="legal"><a href="https://chromewebstore.google.com/detail/ojo-al-precio/moeikollgpcleldjkjogmeeeoglmncac" target="_blank" rel="noopener">🧩 Extensión de Chrome</a> · <a href="index.html">Inicio</a> · <a href="ayuda.html">Ayuda</a> · <a href="terminos.html">Términos y privacidad</a></p>
    <p>Precios referenciales, tomados de cada tienda. Verificá el precio final antes de comprar. · <?= $h($siteName) ?> 🇳🇮</p>
  </footer>
</div>
<?php if ($isAdmin): ?>
<script>
(function(){
  var root = document.getElementById('adminEdit');
  if(!root) return;
  var slug = root.dataset.slug;
  var API = 'api/admin/group_edit.php';
  var search = document.getElementById('aeSearch');
  var results = document.getElementById('aeResults');
  var msg = document.getElementById('aeMsg');
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function money(v,c){ return v==null?'—':(c==='NIO'?'C$':'')+Number(v).toLocaleString('en-US',{maximumFractionDigits:0}); }
  async function post(payload){
    try{
      var r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
      var txt = await r.text();
      var j = null; try{ j = JSON.parse(txt); }catch(_){}
      if(!j) return {ok:false, error:'HTTP '+r.status+': '+txt.slice(0,200)};
      return j;
    }catch(err){ return {ok:false, error:'Error de red: '+err.message}; }
  }
  // Quitar / Desbloquear
  root.addEventListener('click', async function(e){
    var rm = e.target.closest('.ae-remove');
    var un = e.target.closest('.ae-unlock');
    if(!rm && !un) return;
    var btn = rm || un;
    if(rm && !confirm('¿Quitar este producto del comparativo?')) return;
    if(un && !confirm('¿Devolver este producto al modo automático? El matcher volverá a agruparlo solo en la próxima corrida.')) return;
    btn.disabled = true; msg.textContent = '';
    var j = await post({action: rm ? 'remove' : 'unlock', group:slug, product:Number(btn.dataset.id)});
    if(j.ok){ location.reload(); } else { msg.textContent = j.error || 'Error'; btn.disabled = false; }
  });
  // Buscar
  var t;
  search.addEventListener('input', function(){
    clearTimeout(t);
    var q = search.value.trim();
    if(q.length < 2){ results.innerHTML = ''; return; }
    t = setTimeout(async function(){
      results.innerHTML = '<div class="ae-help">Buscando…</div>';
      var r = await fetch(API + '?action=search&exclude_group=' + encodeURIComponent(slug) + '&q=' + encodeURIComponent(q));
      var j = await r.json();
      var its = j.items || [];
      results.innerHTML = its.length ? its.map(function(it){
        var tag = it.in_group ? ' · <span class="ae-ingroup">ya en otro comparativo ('+it.group_members+' prod.)</span>' : '';
        return '<div class="ae-row"><div class="ae-info"><b>'+esc(it.store)+'</b> · '+esc(it.title)+' · '+money(it.price,it.currency)+tag+'</div>'+
          '<button class="ae-add" data-id="'+it.id+'" data-ingroup="'+(it.in_group?1:0)+'" data-members="'+it.group_members+'">Agregar +</button></div>';
      }).join('') : '<div class="ae-help">Sin resultados.</div>';
    }, 300);
  });
  // Agregar
  results.addEventListener('click', async function(e){
    var btn = e.target.closest('.ae-add'); if(!btn) return;
    var merge = false;
    if(btn.dataset.ingroup === '1'){
      merge = confirm('Este producto ya está en otro comparativo con '+btn.dataset.members+' productos.\n\nAceptar = traer TODOS esos productos a este comparativo (unir).\nCancelar = mover solo este producto.');
    }
    btn.disabled = true; msg.textContent = '';
    var j = await post({action:'add', group:slug, product:Number(btn.dataset.id), merge:merge});
    if(j.ok){ location.reload(); } else { msg.textContent = j.error || 'Error'; btn.disabled = false; }
  });
})();
</script>
<?php endif; ?>
<a href="https://whatsapp.com/channel/0029Vb8rtBl35fLzbo8lsb2b" target="_blank" rel="noopener" aria-label="Canal de WhatsApp de ofertas" style="position:fixed;left:16px;bottom:16px;z-index:9999;display:inline-flex;align-items:center;gap:9px;background:#25d366;color:#fff;font-weight:800;font-size:.9rem;padding:12px 17px;border-radius:999px;box-shadow:0 6px 22px rgba(0,0,0,.28);text-decoration:none">
  <svg width="22" height="22" viewBox="0 0 32 32" fill="#fff" aria-hidden="true"><path d="M16 .4C7.4.4.5 7.3.5 15.9c0 2.8.7 5.4 2 7.8L.4 31.6l8.1-2.1c2.3 1.2 4.8 1.9 7.5 1.9 8.6 0 15.5-6.9 15.5-15.5S24.6.4 16 .4zm0 28.3c-2.4 0-4.7-.6-6.7-1.8l-.5-.3-4.8 1.3 1.3-4.7-.3-.5c-1.3-2.1-2-4.5-2-7 0-7.1 5.8-12.9 12.9-12.9s12.9 5.8 12.9 12.9-5.8 12.9-12.8 12.9zm7.1-9.6c-.4-.2-2.3-1.1-2.6-1.3-.4-.1-.6-.2-.9.2-.3.4-1 1.3-1.2 1.5-.2.2-.4.3-.8.1-.4-.2-1.6-.6-3.1-1.9-1.1-1-1.9-2.3-2.2-2.7-.2-.4 0-.6.2-.8.2-.2.4-.4.5-.7.2-.2.2-.4.4-.6.1-.3 0-.5 0-.7-.1-.2-.9-2.1-1.2-2.9-.3-.8-.6-.7-.9-.7h-.7c-.2 0-.6.1-1 .5-.3.4-1.3 1.3-1.3 3.1s1.3 3.6 1.5 3.9c.2.2 2.6 4 6.4 5.6.9.4 1.6.6 2.1.8.9.3 1.7.2 2.3.1.7-.1 2.3-.9 2.6-1.8.3-.9.3-1.7.2-1.8-.1-.2-.3-.3-.7-.5z"/></svg>
  <span style="white-space:nowrap">Ofertas por WhatsApp</span>
</a>
</body>
</html>
