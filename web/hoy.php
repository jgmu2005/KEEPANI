<?php
declare(strict_types=1);

/**
 * PÁGINA PÚBLICA "OFERTAS DE HOY" — server-rendered (SEO) + motor de contenido.
 * Tres secciones con datos que ya calcula el tracker:
 *   1) Bajones reales del día (filtrados por mediana → no ofertas falsas).
 *   2) Precios que no tienen sentido (mayor diferencia entre tiendas, match confiable).
 *   3) Mínimos históricos (en su precio más bajo registrado).
 * Cada ítem trae "Copiar para WhatsApp" (mensaje listo para pegar en el canal).
 *
 *   /hoy.php   (alias sugerido /hoy vía rewrite)
 */

require __DIR__ . '/bootstrap.php';

use OjoAlPrecio\Web\Db;
use OjoAlPrecio\Web\ProductRepository;
use OjoAlPrecio\Web\Settings;
use OjoAlPrecio\Web\Verification;
use OjoAlPrecio\Web\Seo;

// Canal de WhatsApp (público). Si algún día se maneja desde ajustes, mover a Settings.
const WA_CHANNEL = 'https://whatsapp.com/channel/0029Vb8rtBl35fLzbo8lsb2b';

$db   = Db::conn();
$repo = new ProductRepository($db);

// Orden de las secciones 2 y 3: 'recientes' (por último cambio de precio) o el
// default. Así el contenido rota día a día aunque los gaps/mínimos no cambien seguido.
$gapsSort = ($_GET['gaps'] ?? '') === 'recent' ? 'recent' : 'diff';
$lowsSort = ($_GET['lows'] ?? '') === 'recent' ? 'recent' : 'drop';

$dropsAll = $repo->changesList('drop', 60);
$drops    = array_slice($dropsAll, 0, 15);
$gaps     = $repo->biggestGaps(10, $gapsSort);
$lows     = $repo->historicLows(15, $lowsSort);

// La MEJOR bajada de CADA tienda (1 por tienda). Sirve para promocionar tiendas
// que no entran al top-15 por % (E-Tech, El Gallo, las nuevas…). Sale de la misma
// query: deduplicamos por tienda conservando el orden por descuento.
$perStore = []; $seenStore = [];
foreach ($dropsAll as $d) {
    if (($d['direction'] ?? '') !== 'down' || isset($seenStore[$d['store']])) { continue; }
    $seenStore[$d['store']] = true;
    $perStore[] = $d;
}

/** Link a esta misma página cambiando un parámetro de orden (conserva el otro). */
$toggleUrl = static function (string $which, string $val) use ($gapsSort, $lowsSort): string {
    $g = $which === 'gaps' ? $val : $gapsSort;
    $l = $which === 'lows' ? $val : $lowsSort;
    return 'hoy.php?gaps=' . $g . '&lows=' . $l . '#' . $which;
};

$settings = Settings::all($db);
$siteName = $settings['site_name'] ?? 'Ojo al Precio';
$base     = rtrim(Verification::baseUrl(), '/');
$pageUrl  = $base . '/hoy.php';

$h     = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$money = static fn(float $v): string => 'C$' . number_format($v, 2, '.', ',');
$desc  = 'Ofertas reales, mínimos históricos y los precios que no tienen sentido entre tiendas de Nicaragua. Actualizado a diario por ' . $siteName . '.';

/** Mensaje listo para pegar en WhatsApp (con saltos de línea reales). */
$waDrop = static fn(array $d) => "🔻 {$d['title']}\n💰 Ahora " . $money($d['price_now'])
    . ' (antes ' . $money($d['price_prev']) . ', ' . $d['delta_pct'] . "%)\n"
    . "Mirá el historial 👉 {$GLOBALS['base']}/precio.php?id={$d['id']}";
$waGap = static function (array $g) use ($money, $base) {
    $chain = $g['same_chain'] ? ' — ¡y son la MISMA empresa (Unicomer)!' : '';
    return "🤨 {$g['title']}\n{$g['cheap_store']}: " . $money($g['cheap_price'])
        . " vs {$g['pricy_store']}: " . $money($g['pricy_price']) . " — es el MISMO producto{$chain}\n"
        . "Comprá en el barato 👉 {$base}/producto.php?slug={$g['slug']}";
};
$waLow = static fn(array $l) => "📉 {$l['title']}\n¡En su precio MÁS BAJO!: " . $money($l['price_now'])
    . ' (llegó a estar en ' . $money($l['price_peak']) . ")\n"
    . "👉 {$GLOBALS['base']}/precio.php?id={$l['id']}";
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🔥 Ofertas de hoy en Nicaragua · <?= $h($siteName) ?></title>
<meta name="description" content="<?= $h($desc) ?>">
<link rel="canonical" href="<?= $h($pageUrl) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="🔥 Ofertas reales de hoy en Nicaragua">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="<?= $h($pageUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<?= Seo::head($settings, true) ?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
  :root{--brand:#0ea5e9;--brand-dk:#0369a1;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--ok:#16a34a;--bad:#dc2626;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#f1f5f9;line-height:1.5}
  a{color:var(--brand-dk);text-decoration:none}
  .site{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}
  .site .in{max-width:860px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
  .site .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:800}
  .site a{color:#cbd5e1;font-size:.85rem;font-weight:600}
  .wrap{max-width:860px;margin:0 auto;padding:22px 16px 60px}
  h1{font-size:1.6rem;margin:.2rem 0 .3rem}
  .lead{color:var(--muted);margin:0 0 16px}
  .wa-cta{display:flex;align-items:center;gap:12px;background:#dcfce7;border:1px solid #86efac;border-radius:12px;padding:12px 16px;margin:0 0 22px;flex-wrap:wrap}
  .wa-cta b{color:#166534}
  .wa-join{margin-left:auto;background:#25d366;color:#fff;font-weight:800;padding:10px 16px;border-radius:9px;white-space:nowrap}
  h2{font-size:1.15rem;margin:28px 0 4px}
  .sub{color:var(--muted);font-size:.88rem;margin:0 0 12px}
  .toggle{display:flex;gap:6px;margin:0 0 14px}
  .toggle a{font-size:.8rem;font-weight:700;color:var(--muted);background:var(--card);border:1px solid var(--line);padding:5px 12px;border-radius:999px;text-decoration:none}
  .toggle a.on{background:var(--brand);border-color:var(--brand);color:#fff}
  .card{display:flex;gap:14px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin-bottom:10px;flex-wrap:wrap}
  .card img{width:64px;height:64px;object-fit:contain;background:#f8fafc;border-radius:9px;flex:none}
  .card .info{flex:1;min-width:200px}
  .card .tt{font-weight:700;font-size:.95rem;margin-bottom:3px}
  .card .tt a{color:var(--ink)}
  .price{font-weight:900;font-size:1.15rem;color:var(--ok);white-space:nowrap}
  .old{color:var(--muted);text-decoration:line-through;font-size:.85rem;font-weight:600;margin-left:6px}
  .drop-badge{display:inline-block;background:#fee2e2;color:#b91c1c;font-weight:900;font-size:.85rem;padding:3px 10px;border-radius:999px}
  .gap-line{font-size:.95rem;margin-top:2px}
  .gap-line .lo{color:var(--ok);font-weight:800}
  .gap-line .hi{color:var(--bad);font-weight:800}
  .chain{display:inline-block;background:#fffbeb;color:#92400e;border:1px solid #fde68a;font-weight:800;font-size:.72rem;padding:3px 9px;border-radius:999px;margin-top:5px}
  .save{color:var(--muted);font-size:.82rem}
  .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto}
  .copy{cursor:pointer;border:0;background:#25d366;color:#fff;font-weight:800;font-size:.82rem;padding:8px 13px;border-radius:9px}
  .img{cursor:pointer;border:0;background:#7c3aed;color:#fff;font-weight:800;font-size:.82rem;padding:8px 13px;border-radius:9px}
  .view{background:var(--brand);color:#fff;font-weight:700;font-size:.82rem;padding:8px 13px;border-radius:9px}
  .empty{color:var(--muted);background:var(--card);border:1px dashed var(--line);border-radius:12px;padding:16px;text-align:center}
  .foot{margin-top:36px;padding-top:20px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.84rem}
</style>
</head>
<body>
<header class="site"><div class="in">
  <span class="brand"><?= $h($settings['logo_emoji'] ?? '🔎') ?> <?= $h($siteName) ?></span>
  <a href="index.html">Ver todos los productos →</a>
</div></header>

<div class="wrap">
  <h1>🔥 Ofertas reales de hoy</h1>
  <p class="lead">Descuentos de verdad, mínimos históricos y precios que no tienen sentido entre tiendas de Nicaragua. Verificados contra el historial — sin ofertas falsas.</p>

  <div class="wa-cta">
    <span>📢 <b>¿Querés que te lleguen todos los días?</b> Unite al canal de WhatsApp.</span>
    <a class="wa-join" href="<?= $h(WA_CHANNEL) ?>" target="_blank" rel="noopener">Unirme al canal</a>
  </div>

  <!-- 1) BAJONES REALES -->
  <h2>🔻 Bajones reales de hoy</h2>
  <p class="sub">Bajaron respecto a ayer y están por debajo de su precio habitual (no es un descuento falso).</p>
  <?php if (!$drops): ?>
    <div class="empty">Todavía no hay bajones confirmados hoy. Volvé más tarde. 👀</div>
  <?php else: foreach ($drops as $d): ?>
    <div class="card">
      <?php if (!empty($d['image_url'])): ?><img src="<?= $h($d['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
      <div class="info">
        <div class="tt"><a href="precio.php?id=<?= (int) $d['id'] ?>"><?= $h($d['title']) ?></a></div>
        <div><span class="price"><?= $money((float) $d['price_now']) ?></span><span class="old"><?= $money((float) $d['price_prev']) ?></span> · <?= $h($d['store_name']) ?></div>
      </div>
      <span class="drop-badge"><?= (float) $d['delta_pct'] ?>%</span>
      <div class="actions">
        <button class="copy" data-wa="<?= $h($waDrop($d)) ?>">📋 Copiar</button>
        <button class="img" data-imgsrc="img.php?id=<?= (int) $d['id'] ?>" data-title="<?= $h($d['title']) ?>" data-price="Ahora <?= $h($money((float) $d['price_now'])) ?>" data-note="antes <?= $h($money((float) $d['price_prev'])) ?> · <?= $h($d['store_name']) ?>" data-tag="<?= (float) $d['delta_pct'] ?>%">🖼️ Imagen</button>
        <a class="view" href="precio.php?id=<?= (int) $d['id'] ?>">Ver</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <!-- 1b) MEJOR BAJADA POR TIENDA -->
  <h2 id="portienda">🏪 La mejor bajada de cada tienda</h2>
  <p class="sub">Una oferta destacada por tienda — así promocionás todas, no solo las del mayor descuento.</p>
  <?php if (!$perStore): ?>
    <div class="empty">Todavía no hay bajones por tienda. Volvé más tarde. 👀</div>
  <?php else: foreach ($perStore as $d): ?>
    <div class="card">
      <?php if (!empty($d['image_url'])): ?><img src="<?= $h($d['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
      <div class="info">
        <div class="tt"><a href="precio.php?id=<?= (int) $d['id'] ?>"><?= $h($d['title']) ?></a></div>
        <div><span class="price"><?= $money((float) $d['price_now']) ?></span><span class="old"><?= $money((float) $d['price_prev']) ?></span> · <b><?= $h($d['store_name']) ?></b></div>
      </div>
      <span class="drop-badge"><?= (float) $d['delta_pct'] ?>%</span>
      <div class="actions">
        <button class="copy" data-wa="<?= $h($waDrop($d)) ?>">📋 Copiar</button>
        <button class="img" data-imgsrc="img.php?id=<?= (int) $d['id'] ?>" data-title="<?= $h($d['title']) ?>" data-price="Ahora <?= $h($money((float) $d['price_now'])) ?>" data-note="antes <?= $h($money((float) $d['price_prev'])) ?> · <?= $h($d['store_name']) ?>" data-tag="<?= (float) $d['delta_pct'] ?>%">🖼️ Imagen</button>
        <a class="view" href="precio.php?id=<?= (int) $d['id'] ?>">Ver</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <!-- 2) PRECIOS QUE NO TIENEN SENTIDO -->
  <h2 id="gaps">🤨 Precios que no tienen sentido</h2>
  <p class="sub">El mismo producto, precio muy distinto según la tienda. A veces… de la misma empresa.</p>
  <div class="toggle">
    <a href="<?= $h($toggleUrl('gaps', 'diff')) ?>"<?= $gapsSort === 'diff' ? ' class="on"' : '' ?>>Mayor diferencia</a>
    <a href="<?= $h($toggleUrl('gaps', 'recent')) ?>"<?= $gapsSort === 'recent' ? ' class="on"' : '' ?>>🆕 Recientes</a>
  </div>
  <?php if (!$gaps): ?>
    <div class="empty">Sin diferencias grandes ahora mismo.</div>
  <?php else: foreach ($gaps as $g): ?>
    <div class="card">
      <?php if (!empty($g['image_url'])): ?><img src="<?= $h($g['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
      <div class="info">
        <div class="tt"><a href="producto.php?slug=<?= $h(rawurlencode($g['slug'])) ?>"><?= $h($g['title']) ?></a></div>
        <div class="gap-line"><span class="lo"><?= $money((float) $g['cheap_price']) ?></span> en <?= $h($g['cheap_store']) ?> · <span class="hi"><?= $money((float) $g['pricy_price']) ?></span> en <?= $h($g['pricy_store']) ?></div>
        <div class="save">Ahorrás <?= $money((float) $g['save']) ?> (<?= (int) $g['diff_pct'] ?>% más caro en el otro)</div>
        <?php if (!empty($g['same_chain'])): ?><span class="chain">⚠️ Misma empresa · Unicomer</span><?php endif; ?>
      </div>
      <div class="actions">
        <button class="copy" data-wa="<?= $h($waGap($g)) ?>">📋 Copiar</button>
        <button class="img" data-imgsrc="img.php?group=<?= $h(rawurlencode($g['slug'])) ?>" data-title="<?= $h($g['title']) ?>" data-price="<?= $h($money((float) $g['cheap_price'])) ?> en <?= $h($g['cheap_store']) ?>" data-note="vs <?= $h($money((float) $g['pricy_price'])) ?> en <?= $h($g['pricy_store']) ?> — mismo producto" data-tag="<?= $g['same_chain'] ? 'Misma empresa · Unicomer' : (int) $g['diff_pct'] . '% de diferencia' ?>">🖼️ Imagen</button>
        <a class="view" href="producto.php?slug=<?= $h(rawurlencode($g['slug'])) ?>">Comparar</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <!-- 3) MÍNIMOS HISTÓRICOS -->
  <h2 id="lows">📉 En su precio más bajo</h2>
  <p class="sub">Productos que hoy están en el precio más bajo que les hemos registrado.</p>
  <div class="toggle">
    <a href="<?= $h($toggleUrl('lows', 'drop')) ?>"<?= $lowsSort === 'drop' ? ' class="on"' : '' ?>>Mayor caída</a>
    <a href="<?= $h($toggleUrl('lows', 'recent')) ?>"<?= $lowsSort === 'recent' ? ' class="on"' : '' ?>>🆕 Recientes</a>
  </div>
  <?php if (!$lows): ?>
    <div class="empty">Sin mínimos históricos destacados por ahora.</div>
  <?php else: foreach ($lows as $l): ?>
    <div class="card">
      <?php if (!empty($l['image_url'])): ?><img src="<?= $h($l['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
      <div class="info">
        <div class="tt"><a href="precio.php?id=<?= (int) $l['id'] ?>"><?= $h($l['title']) ?></a></div>
        <div><span class="price"><?= $money((float) $l['price_now']) ?></span><span class="old"><?= $money((float) $l['price_peak']) ?></span> · <?= $h($l['store_name']) ?> · <b><?= (int) $l['off_peak_pct'] ?>% bajo su pico</b></div>
      </div>
      <div class="actions">
        <button class="copy" data-wa="<?= $h($waLow($l)) ?>">📋 Copiar</button>
        <button class="img" data-imgsrc="img.php?id=<?= (int) $l['id'] ?>" data-title="<?= $h($l['title']) ?>" data-price="<?= $h($money((float) $l['price_now'])) ?>" data-note="su precio más bajo · pico <?= $h($money((float) $l['price_peak'])) ?>" data-tag="<?= (int) $l['off_peak_pct'] ?>% bajo su pico">🖼️ Imagen</button>
        <a class="view" href="precio.php?id=<?= (int) $l['id'] ?>">Ver</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <div class="foot">
    <?= $h($siteName) ?> · precios de referencia, verificá siempre en la tienda antes de comprar.<br>
    <a href="index.html">Inicio</a> · <a href="<?= $h(WA_CHANNEL) ?>" target="_blank" rel="noopener">Canal de WhatsApp</a>
  </div>
</div>

<!-- Plantilla oculta para generar la imagen compartible (html2canvas) -->
<div id="cardTpl" style="display:none;position:fixed;left:-9999px;top:0;width:600px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;overflow:hidden">
  <div style="padding:22px 26px 4px;display:flex;align-items:center;justify-content:space-between">
    <div style="font-weight:900;font-size:22px">🔎 Ojo al Precio</div>
    <div style="font-size:13px;color:#7dd3fc;font-weight:700">ofertas reales · Nicaragua</div>
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
    <div style="text-align:center;font-size:14px;color:#94a3b8;margin-top:12px;font-weight:600">📲 Unite al canal de WhatsApp · ofertas reales del día</div>
  </div>
</div>

<script>
// Copiar para WhatsApp
document.addEventListener('click', function(e){
  var b = e.target.closest('[data-wa]'); if(!b) return;
  var msg = b.getAttribute('data-wa');
  navigator.clipboard.writeText(msg).then(function(){
    var prev = b.textContent; b.textContent = '✅ ¡Copiado!';
    setTimeout(function(){ b.textContent = prev; }, 1600);
  }).catch(function(){ b.textContent = '⚠️ Copiá manual'; });
});

// Generar imagen compartible (html2canvas + proxy same-origin img.php)
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
  a.download = 'ojoalprecio-oferta.png';
  a.href = canvas.toDataURL('image/png');
  a.click();
}
document.addEventListener('click', function(e){
  var b = e.target.closest('.img'); if(!b) return;
  var prev = b.textContent; b.textContent = '⏳';
  makeCard(b.dataset).then(function(){ b.textContent = prev; })
    .catch(function(){ b.textContent = '⚠️'; setTimeout(function(){ b.textContent = prev; }, 1500); });
});
</script>
</body>
</html>
