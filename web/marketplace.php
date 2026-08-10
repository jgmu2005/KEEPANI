<?php
declare(strict_types=1);

/**
 * PÁGINA PÚBLICA — MARKETPLACE de tiendas locales (Treinta). Aislada del tracker.
 *   /marketplace   (o /marketplace?store=slug&page=N)
 * Muestra producto + precio actual / más bajo / más alto. Pedido por WhatsApp.
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\Seo;
use OjoAlPrecio\Web\Marketplace\MarketplaceRepo;

$db   = Db::conn();
$repo = new MarketplaceRepo($db);
$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$base     = rtrim(Verification::baseUrl(), '/');

$h   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmt = static function (?float $v, string $cur = 'NIO'): string {
    if ($v === null) return '—';
    return ($cur === 'USD' ? 'US$' : 'C$') . number_format($v, 2);
};

$storeSlug = trim((string) ($_GET['store'] ?? '')) ?: null;
$sort      = in_array($_GET['sort'] ?? '', ['price_asc', 'price_desc', 'name'], true) ? $_GET['sort'] : 'recent';
$hideOos   = ($_GET['stock'] ?? '') !== 'all';   // por defecto: ocultar fuera de inventario
$page      = max(1, (int) ($_GET['page'] ?? 1));
$per       = 48;
$offset    = ($page - 1) * $per;

$stores = $repo->storeFilter();
$res    = $repo->listProducts($storeSlug, $per, $offset, $sort, $hideOos);
$items  = $res['items'];
$total  = $res['total'];
$pages  = max(1, (int) ceil($total / $per));

$curStoreName = null;
if ($storeSlug) {
    foreach ($stores as $s) { if ($s['slug'] === $storeSlug) { $curStoreName = $s['name']; } }
}

// Construye una URL de /marketplace preservando tienda + orden (+ página).
$mkUrl = static function (array $over = []) use ($base, $storeSlug, $sort, $hideOos): string {
    $st  = array_key_exists('store', $over) ? $over['store'] : $storeSlug;
    $so  = array_key_exists('sort',  $over) ? $over['sort']  : $sort;
    $stk = array_key_exists('stock', $over) ? $over['stock'] : ($hideOos ? 'hide' : 'all');
    $pg  = $over['page'] ?? 1;
    $q = [];
    if ($st)                 { $q['store'] = $st; }
    if ($so && $so !== 'recent') { $q['sort'] = $so; }
    if ($stk === 'all')      { $q['stock'] = 'all'; }
    if ($pg > 1)             { $q['page'] = $pg; }
    return $base . '/marketplace' . ($q ? '?' . http_build_query($q) : '');
};
$sortOpts = ['recent' => '🆕 Recientes', 'price_asc' => '⬆️ Precio: menor a mayor', 'price_desc' => '⬇️ Precio: mayor a menor'];

$metaTitle = ($curStoreName ? $curStoreName . ' — ' : '') . 'Marketplace de tiendas locales en Nicaragua';
$metaDesc  = 'Productos de tiendas y emprendimientos locales de Nicaragua con su precio actual, más bajo y más alto. ' . $siteName . '.';
$pageUrl   = $base . '/marketplace' . ($storeSlug ? '?store=' . rawurlencode($storeSlug) : '');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($metaTitle) ?> · <?= $h($siteName) ?></title>
<meta name="description" content="<?= $h($metaDesc) ?>">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:title" content="<?= $h($metaTitle) ?>">
<meta property="og:description" content="<?= $h($metaDesc) ?>">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<?= Seo::head($settings, true) ?>
<style>
  :root{--brand:#0ea5e9;--brand-dk:#0369a1;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--ok:#16a34a;--bad:#dc2626;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f1f5f9;line-height:1.5}
  a{color:var(--brand-dk);text-decoration:none}
  .site{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}
  .site .in{max-width:1100px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .site .brand{display:flex;align-items:center;gap:10px;color:#fff}
  .site .logo{font-size:1.7rem}
  .site b{font-size:1.05rem;display:block;line-height:1.15}
  .site small{color:#94a3b8;font-size:.72rem}
  .site .back{color:#7dd3fc;font-weight:600;font-size:.85rem;white-space:nowrap}
  .wrap{max-width:1100px;margin:0 auto;padding:20px 16px 60px}
  h1{font-size:1.5rem;margin:.2rem 0}
  .lead{color:var(--muted);margin:.2rem 0 16px;max-width:640px}
  .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
  .filters a{padding:7px 13px;border:1px solid var(--line);background:#fff;border-radius:999px;font-size:.84rem;font-weight:600;color:var(--ink)}
  .filters a.on{background:var(--brand);color:#fff;border-color:var(--brand)}
  .sortbar{display:flex;gap:8px;flex-wrap:wrap;margin:-6px 0 18px}
  .sortbar a{padding:6px 12px;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:.8rem;font-weight:600;color:var(--muted)}
  .sortbar a.on{background:#0f172a;color:#fff;border-color:#0f172a}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
  .card .img{aspect-ratio:1;background:#f8fafc;display:grid;place-items:center;position:relative}
  .card .img img{width:100%;height:100%;object-fit:contain}
  .card .out{position:absolute;bottom:6px;left:6px;background:#fee2e2;color:#b91c1c;font-size:.66rem;font-weight:800;padding:2px 7px;border-radius:999px}
  .card .body{padding:11px 12px;display:flex;flex-direction:column;gap:6px;flex:1}
  .store{font-size:.68rem;text-transform:uppercase;color:var(--brand-dk);font-weight:700;letter-spacing:.02em}
  .name{font-size:.85rem;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .price{font-size:1.2rem;font-weight:800}
  .range{font-size:.7rem;color:var(--muted);margin-top:auto}
  .range b.lo{color:var(--ok)} .range b.hi{color:var(--bad)}
  .buy{margin-top:8px;background:#25d366;color:#fff;text-align:center;padding:8px;border-radius:8px;font-weight:700;font-size:.82rem}
  .pager{display:flex;gap:8px;justify-content:center;align-items:center;margin-top:26px}
  .pager a,.pager span{padding:8px 14px;border:1px solid var(--line);border-radius:8px;background:#fff;font-weight:600;font-size:.85rem}
  .pager span.cur{background:var(--brand);color:#fff;border-color:var(--brand)}
  .empty{background:#fff;border:1px solid var(--line);border-radius:14px;padding:40px;text-align:center;color:var(--muted)}
  .disc{margin-top:24px;font-size:.78rem;color:var(--muted);background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:12px 14px}
  .site-foot{margin-top:30px;padding-top:18px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.84rem}
</style>
</head>
<body>
<header class="site"><div class="in">
  <a class="brand" href="<?= $h($base) ?>/index.html"><span class="logo">🛍️</span>
    <span><b><?= $h($siteName) ?> · Marketplace</b><small>Tiendas y emprendimientos locales · Nicaragua 🇳🇮</small></span></a>
  <a class="back" href="<?= $h($base) ?>/index.html">← Volver al tracker</a>
</div></header>

<div class="wrap">
  <h1>🛍️ Marketplace de tiendas locales</h1>
  <p class="lead">Productos de tiendas y emprendimientos nicaragüenses (en Treinta), con su <b>precio actual</b>, el <b>más bajo</b> y el <b>más alto</b> que registramos. El pedido se hace directo con la tienda por WhatsApp.</p>

  <div class="filters">
    <a href="<?= $h($mkUrl(['store' => null, 'page' => 1])) ?>" class="<?= $storeSlug ? '' : 'on' ?>">Todas</a>
    <?php foreach ($stores as $s): ?>
      <a href="<?= $h($mkUrl(['store' => $s['slug'], 'page' => 1])) ?>" class="<?= $storeSlug === $s['slug'] ? 'on' : '' ?>"><?= $h($s['name']) ?> <small>(<?= (int) $s['n'] ?>)</small></a>
    <?php endforeach; ?>
  </div>

  <div class="sortbar">
    <?php foreach ($sortOpts as $k => $label): ?>
      <a href="<?= $h($mkUrl(['sort' => $k, 'page' => 1])) ?>" class="<?= $sort === $k ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <a href="<?= $h($mkUrl(['stock' => $hideOos ? 'all' : 'hide', 'page' => 1])) ?>" class="<?= $hideOos ? 'on' : '' ?>" style="margin-left:auto"><?= $hideOos ? '✅' : '☐' ?> Ocultar agotados</a>
  </div>

  <?php if (!$items): ?>
    <div class="empty">Todavía no hay productos cargados en el marketplace. Volvé pronto. 🛍️</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $p):
          $cur = $p['currency'] ?: 'NIO';
          $hasRange = $p['min_price'] !== null && $p['max_price'] !== null && $p['max_price'] > $p['min_price'];
          $buy = $p['whatsapp'] ? 'https://wa.me/' . preg_replace('/\D/', '', $p['whatsapp']) : $p['store_url'];
      ?>
        <div class="card">
          <div class="img">
            <?php if ($p['image_url']): ?><img src="<?= $h($p['image_url']) ?>" alt="<?= $h($p['name']) ?>" loading="lazy"><?php else: ?>📦<?php endif; ?>
            <?php if (!$p['in_stock']): ?><span class="out">Agotado</span><?php endif; ?>
          </div>
          <div class="body">
            <span class="store"><?= $h($p['store_name']) ?></span>
            <div class="name"><?= $h($p['name']) ?></div>
            <div class="price"><?= $fmt($p['price'], $cur) ?></div>
            <div class="range">
              <?php if ($hasRange): ?>
                más bajo <b class="lo"><?= $fmt($p['min_price'], $cur) ?></b> · más alto <b class="hi"><?= $fmt($p['max_price'], $cur) ?></b>
              <?php else: ?>
                Sin variación registrada aún
              <?php endif; ?>
            </div>
            <a class="buy" href="<?= $h($buy) ?>" target="_blank" rel="noopener nofollow">🛒 Pedir a la tienda</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php if ($page > 1): ?><a href="<?= $h($mkUrl(['page' => $page - 1])) ?>">‹ Anterior</a><?php endif; ?>
        <span class="cur"><?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="<?= $h($mkUrl(['page' => $page + 1])) ?>">Siguiente ›</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="disc">ℹ️ El marketplace lista productos de tiendas locales de terceros (vía Treinta). Los precios y la disponibilidad los define cada tienda; verificá con ella antes de comprar. ¿Sos dueño de una tienda y no querés aparecer? Escribinos y la quitamos.</div>

  <footer class="site-foot">
    <a href="<?= $h($base) ?>/index.html">Inicio</a> · <a href="<?= $h($base) ?>/ayuda.html">Ayuda</a> · <a href="<?= $h($base) ?>/terminos.html">Términos</a> · <?= $h($siteName) ?> 🇳🇮
  </footer>
</div>
</body>
</html>
