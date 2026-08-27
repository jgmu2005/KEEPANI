<?php
declare(strict_types=1);

/**
 * PÁGINA PÚBLICA — Cazaofertas Walmart (liquidaciones ≥30%).
 * Feed de las bajas fuertes recientes del catálogo completo de Walmart.
 *   /liquidaciones  (via .htaccess) o /liquidaciones.php
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\Seo;
use OjoAlPrecio\Web\Walmart\WalmartWatch;

$db   = Db::conn();
$repo = new WalmartWatch($db);

$sort   = ($_GET['sort'] ?? '') === 'pct' ? 'pct' : 'recent';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = 48;
$res    = $repo->feed($per, ($page - 1) * $per, $sort);
$pages  = max(1, (int) ceil($res['total'] / $per));

$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$base     = rtrim(Verification::baseUrl(), '/');
$h    = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmt  = static fn(float $v, string $c): string => ($c === 'USD' ? 'US$' : 'C$') . number_format($v, 2);
$pageUrl = $base . '/liquidaciones';
$mkUrl = static function (array $o = []) use ($base, $sort): string {
    $so = $o['sort'] ?? $sort; $pg = $o['page'] ?? 1; $q = [];
    if ($so && $so !== 'recent') { $q['sort'] = $so; }
    if ($pg > 1) { $q['page'] = $pg; }
    return $base . '/liquidaciones' . ($q ? '?' . http_build_query($q) : '');
};
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Liquidaciones de Walmart Nicaragua — bajas de precio ≥30% · <?= $h($siteName) ?></title>
<meta name="description" content="Productos de Walmart Nicaragua con bajas de precio fuertes (≥30%), típicas de liquidación por bajo inventario. Actualizado a diario.">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:title" content="🔥 Liquidaciones de Walmart Nicaragua">
<meta property="og:description" content="Bajas de precio ≥30% en el catálogo de Walmart NI, actualizadas a diario.">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<?= Seo::head($settings, true) ?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
  :root{--brand:#0ea5e9;--brand-dk:#0369a1;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--ok:#16a34a;--bad:#dc2626;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f1f5f9;line-height:1.5}
  a{color:var(--brand-dk);text-decoration:none}
  .site{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}
  .site .in{max-width:1080px;margin:0 auto;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
  .site b{font-size:1.05rem}.site small{color:#94a3b8;font-size:.72rem;display:block}
  .site .back{color:#7dd3fc;font-weight:600;font-size:.85rem;white-space:nowrap}
  .wrap{max-width:1080px;margin:0 auto;padding:22px 16px 70px}
  h1{font-size:1.5rem;margin:.2rem 0 .3rem}
  .lead{color:var(--muted);margin:0 0 16px;font-size:.95rem}
  .sortbar{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}
  .sortbar a{padding:7px 13px;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:.82rem;font-weight:600;color:var(--muted)}
  .sortbar a.on{background:#0f172a;color:#fff;border-color:#0f172a}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
  .card .img{aspect-ratio:1;background:#f8fafc;display:grid;place-items:center;position:relative}
  .card .img img{width:100%;height:100%;object-fit:contain}
  .off{position:absolute;top:8px;left:8px;background:var(--bad);color:#fff;font-weight:800;font-size:.82rem;padding:3px 9px;border-radius:999px}
  .card .body{padding:12px;display:flex;flex-direction:column;gap:4px;flex:1}
  .brand{font-size:.68rem;font-weight:700;text-transform:uppercase;color:var(--brand-dk)}
  .title{font-size:.86rem;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.3em}
  .prices{margin-top:auto;display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
  .now{font-size:1.15rem;font-weight:800;color:var(--ok)}
  .was{color:var(--muted);text-decoration:line-through;font-size:.85rem}
  .when{font-size:.7rem;color:var(--muted)}
  .go{display:block;text-align:center;background:var(--brand);color:#fff;font-weight:700;padding:9px;border-radius:8px;margin-top:8px;font-size:.85rem}
  .actions{display:flex;gap:6px;margin-top:8px}
  .actions .go{flex:1;margin-top:0;padding:8px 6px}
  .imgbtn{flex:none;width:40px;border:0;background:#7c3aed;color:#fff;font-size:1rem;border-radius:8px;cursor:pointer;line-height:1}
  .imgbtn:disabled{opacity:.6}
  .empty{background:#fff;border:1px solid var(--line);border-radius:14px;padding:40px 20px;text-align:center;color:var(--muted)}
  .pager{display:flex;gap:12px;justify-content:center;align-items:center;margin-top:26px}
  .pager a{background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px 14px;font-weight:600}
  .pager .cur{color:var(--muted);font-size:.9rem}
  .note{margin-top:18px;font-size:.8rem;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 14px}
  .site-foot{margin-top:34px;padding-top:18px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.84rem}
</style>
</head>
<body>
<header class="site"><div class="in">
  <a class="brand" href="<?= $h($base) ?>/index.html" style="color:#fff"><b>🔎 <?= $h($siteName) ?></b><small>Liquidaciones · Nicaragua 🇳🇮</small></a>
  <a class="back" href="<?= $h($base) ?>/index.html">← Inicio</a>
</div></header>
<div class="wrap">
  <h1>🔥 Liquidaciones de Walmart</h1>
  <p class="lead">Productos de Walmart Nicaragua con <b>bajas de precio ≥30%</b> — las típicas de remate por bajo inventario. Se revisa el catálogo completo a diario.</p>
  <p class="lead" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;color:#92400e">💎 Con la <a href="<?= $h($base) ?>/index.html#planes" style="color:#b45309;font-weight:700">suscripción mensual</a> te llegan estas liquidaciones <b>por email</b> apenas aparecen.</p>

  <div class="sortbar">
    <a href="<?= $h($mkUrl(['sort' => 'recent', 'page' => 1])) ?>" class="<?= $sort === 'recent' ? 'on' : '' ?>">🆕 Más recientes</a>
    <a href="<?= $h($mkUrl(['sort' => 'pct', 'page' => 1])) ?>" class="<?= $sort === 'pct' ? 'on' : '' ?>">⬇️ Mayor descuento</a>
  </div>

  <?php if (!$res['items']): ?>
    <div class="empty">Todavía no detectamos liquidaciones. Se van sumando a medida que Walmart baja precios. 🔎</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($res['items'] as $it): $c = $it['currency']; ?>
        <div class="card">
          <div class="img">
            <span class="off">-<?= (int) round($it['pct']) ?>%</span>
            <?php if ($it['image_url']): ?><img src="<?= $h($it['image_url']) ?>" alt="<?= $h($it['title']) ?>" loading="lazy"><?php else: ?>📦<?php endif; ?>
          </div>
          <div class="body">
            <?php if ($it['brand']): ?><span class="brand"><?= $h($it['brand']) ?></span><?php endif; ?>
            <div class="title"><?= $h($it['title'] ?: 'Producto') ?></div>
            <div class="prices">
              <span class="now"><?= $fmt($it['new_price'], $c) ?></span>
              <span class="was"><?= $fmt($it['ref_price'] > $it['new_price'] ? $it['ref_price'] : $it['old_price'], $c) ?></span>
            </div>
            <div class="when"><?= $h(date('d/m/Y', strtotime($it['detected_at']))) ?></div>
            <?php $wasP = $it['ref_price'] > $it['new_price'] ? $it['ref_price'] : $it['old_price']; ?>
            <div class="actions">
              <?php if ($it['url']): ?><a class="go" href="<?= $h($it['url']) ?>" target="_blank" rel="noopener">Ver en Walmart ↗</a><?php endif; ?>
              <button class="imgbtn" title="Generar imagen para compartir"
                data-imgsrc="img.php?wm=<?= (int) $it['id'] ?>"
                data-title="<?= $h($it['title'] ?: 'Producto') ?>"
                data-price="<?= $h($fmt($it['new_price'], $c)) ?>"
                data-note="antes <?= $h($fmt($wasP, $c)) ?> · Walmart Nicaragua"
                data-tag="-<?= (int) round($it['pct']) ?>% en Walmart">🖼️</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="pager">
      <?php if ($page > 1): ?><a href="<?= $h($mkUrl(['page' => $page - 1])) ?>">‹ Anterior</a><?php endif; ?>
      <span class="cur"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= $h($mkUrl(['page' => $page + 1])) ?>">Siguiente ›</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="note">ℹ️ El "precio anterior" es el más alto que registramos para ese producto (su precio normal). Los precios son referenciales; verificá el precio final en Walmart antes de comprar.</div>

  <footer class="site-foot">
    <a href="https://chromewebstore.google.com/detail/ojo-al-precio/moeikollgpcleldjkjogmeeeoglmncac" target="_blank" rel="noopener">🧩 Extensión de Chrome</a> · <a href="<?= $h($base) ?>/index.html">Inicio</a> · <a href="<?= $h($base) ?>/ayuda.html">Ayuda</a> · <?= $h($siteName) ?> 🇳🇮
  </footer>
</div>

<!-- Plantilla oculta para generar la imagen compartible (html2canvas) -->
<div id="cardTpl" style="display:none;position:fixed;left:-9999px;top:0;width:600px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;overflow:hidden">
  <div style="padding:22px 26px 4px;display:flex;align-items:center;justify-content:space-between">
    <div style="font-weight:900;font-size:22px">🔎 Ojo al Precio</div>
    <div style="font-size:13px;color:#7dd3fc;font-weight:700">liquidaciones · Nicaragua</div>
  </div>
  <div style="background:#fff;margin:14px 26px 0;border-radius:16px;padding:18px;text-align:center">
    <img class="ct-img" src="" alt="" style="max-width:100%;height:250px;object-fit:contain">
  </div>
  <div style="padding:18px 26px 6px">
    <div class="ct-tag" style="display:none;background:#fecaca;color:#7f1d1d;font-weight:900;font-size:16px;padding:6px 14px;border-radius:999px;margin-bottom:12px"></div>
    <div class="ct-title" style="font-weight:800;font-size:23px;line-height:1.25;margin-bottom:12px"></div>
    <div class="ct-price" style="font-weight:900;font-size:36px;color:#4ade80;line-height:1.1"></div>
    <div class="ct-note" style="font-size:17px;color:#cbd5e1;margin-top:6px"></div>
  </div>
  <div style="padding:18px 26px 24px;margin-top:16px;border-top:1px solid #334155">
    <div style="background:linear-gradient(90deg,#f97316,#fb923c);color:#fff;font-weight:900;font-size:27px;text-align:center;padding:15px;border-radius:12px;letter-spacing:.3px;box-shadow:0 4px 18px rgba(249,115,22,.45)">OjoAlPrecio.online</div>
    <div style="text-align:center;font-size:14px;color:#94a3b8;margin-top:12px;font-weight:600">🔥 Liquidaciones de Walmart · actualizado a diario</div>
  </div>
</div>

<script>
async function makeCard(ds){
  var t = document.getElementById('cardTpl');
  t.querySelector('.ct-title').textContent = ds.title || '';
  t.querySelector('.ct-price').textContent = ds.price || '';
  t.querySelector('.ct-note').textContent  = ds.note || '';
  var tag = t.querySelector('.ct-tag');
  tag.textContent = ds.tag || ''; tag.style.display = ds.tag ? 'inline-block' : 'none';
  var im = t.querySelector('.ct-img'); im.src = ds.imgsrc || '';
  await new Promise(function(res){ if(!ds.imgsrc || im.complete){ res(); } else { im.onload = res; im.onerror = res; } });
  t.style.display = 'block';
  var canvas = await html2canvas(t, {scale: 2, backgroundColor: null, logging: false, useCORS: true});
  t.style.display = 'none';
  var a = document.createElement('a');
  a.download = 'ojoalprecio-liquidacion.png';
  a.href = canvas.toDataURL('image/png');
  a.click();
}
document.addEventListener('click', function(e){
  var b = e.target.closest('.imgbtn'); if(!b) return;
  var prev = b.textContent; b.textContent = '⏳'; b.disabled = true;
  makeCard(b.dataset).then(function(){ b.textContent = prev; b.disabled = false; })
    .catch(function(){ b.textContent = '⚠️'; setTimeout(function(){ b.textContent = prev; b.disabled = false; }, 1500); });
});
</script>
</body>
</html>
