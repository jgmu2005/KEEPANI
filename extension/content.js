/* Ojo al Precio — content script.
 * Detecta el producto en Sinsa/Siman/Copasa, consulta la API pública e
 * inyecta una tarjeta con el historial de precios debajo del precio. */
(function () {
  'use strict';

  const API  = 'https://ojoalprecio.online/api';
  const SITE = 'https://ojoalprecio.online';

  const host = location.hostname.replace(/^www\./, '');
  const MAGENTO = { platform: 'magento', anchor: '.product-info-main .price-box' };
  const VTEX    = { platform: 'vtex',    anchor: '[class*="sellingPriceValue"]' }; // cubre v1 y v3 del componente
  const CONF = {
    'sinsa.com.ni':           VTEX,
    'ni.siman.com':           VTEX,
    'walmart.com.ni':         VTEX,
    'copasa.com.ni':          { platform: 'copasa', anchor: '.name-product' },
    'elgallomasgallo.com.ni': MAGENTO,
    'lacuracaonline.com':     MAGENTO,
    'radioshackla.com':       MAGENTO,
    'almacenestropigas.com':  MAGENTO,
    'pricesmart.com':         { platform: 'pricesmart', anchor: '.sf-price' },
  }[host];
  if (!CONF) return;
  console.log('[Ojo al Precio] extensión activa en', host);

  const money = (v, cur) => v == null ? '—'
    : (cur === 'USD' ? '$' : 'C$') + new Intl.NumberFormat('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function isProduct() {
    const p = location.pathname;
    switch (CONF.platform) {
      case 'vtex':       return /-\d+\/p(\/|\?|#|$)/.test(p);            // .../slug-{id}/p
      case 'magento':    return /-\d+(\/p)?(\/|\?|#|$)/.test(p);         // Gallo .../slug-{id} · Unicomer .../slug-{id}/p
      case 'copasa':     return /\/Product\/Detail\//i.test(p);
      case 'pricesmart': return /\/producto\/.+\/\d+/.test(p);          // /es-ni/producto/{slug}/{pid}
      default:           return false;
    }
  }

  // Mini-gráfica SVG (sin dependencias).
  function sparkline(vals) {
    vals = vals.filter(v => v != null);
    if (vals.length === 0) return '';
    if (vals.length === 1) {
      return '<svg viewBox="0 0 100 40" preserveAspectRatio="none" class="oap-svg"><circle cx="50" cy="20" r="3" fill="#0ea5e9"/></svg>';
    }
    const min = Math.min(...vals), max = Math.max(...vals), range = (max - min) || 1;
    const pts = vals.map((v, i) => {
      const x = (i / (vals.length - 1)) * 100;
      const y = 38 - ((v - min) / range) * 36;
      return x.toFixed(1) + ',' + y.toFixed(1);
    });
    return '<svg viewBox="0 0 100 40" preserveAspectRatio="none" class="oap-svg">'
      + '<polygon points="0,40 ' + pts.join(' ') + ' 100,40" fill="rgba(14,165,233,.12)"/>'
      + '<polyline points="' + pts.join(' ') + '" fill="none" stroke="#0ea5e9" stroke-width="1.5" vector-effect="non-scaling-stroke"/>'
      + '</svg>';
  }

  function removeWidget() {
    const w = document.getElementById('oap-widget');
    if (w) w.remove();
  }

  function mount(anchor, html) {
    removeWidget();
    const div = document.createElement('div');
    div.id = 'oap-widget';
    div.innerHTML = html;
    // OJO VTEX: closest('[class*="sellingPrice"]') se devolvía a sí mismo (el
    // span sellingPriceValue), inyectando el widget INLINE dentro del precio y
    // clipeándolo. Subimos a un contenedor de bloque real del precio.
    const target = CONF.platform === 'vtex'
      ? (anchor.closest('[class*="prices-container"]')
         || anchor.closest('[class*="sellingPrice"]:not([class*="sellingPriceValue"])')
         || anchor.parentElement || anchor)
      : anchor; // magento (.price-box), pricesmart (.sf-price), copasa (.name-product)
    target.parentNode.insertBefore(div, target.nextSibling);
    return div;
  }

  const shell = inner => '<div class="oap-card"><div class="oap-head"><span class="oap-logo">👁️ Ojo al Precio</span></div>' + inner + '</div>';

  function renderError(div) {
    div.innerHTML = shell('<div class="oap-body oap-muted">No se pudo cargar el historial.</div>');
  }

  function renderTrack(div) {
    div.innerHTML = shell('<div class="oap-body"><p class="oap-muted">Aún no seguimos este producto.</p>'
      + '<button class="oap-btn oap-primary" id="oap-track">➕ Rastrear su precio</button></div>');
    div.querySelector('#oap-track').addEventListener('click', () => {
      div.querySelector('#oap-track').textContent = 'Rastreando…';
      fetch(API + '/track.php?url=' + encodeURIComponent(location.href))
        .then(r => r.json())
        .then(j => j.ok ? renderChart(div, j) : renderError(div))
        .catch(() => renderError(div));
    });
  }

  function dealBadge(deal) {
    if (!deal) return '';
    if (deal.verdict === 'low')  return '<span class="oap-deal oap-deal-low">🔥 Precio más bajo registrado</span>';
    if (deal.verdict === 'fake') return '<span class="oap-deal oap-deal-fake">⚠️ Descuento poco fiable</span>';
    return '';
  }

  function renderChart(div, j) {
    const p = j.product, s = j.stats, h = j.history, cur = s.currency || 'NIO';
    const detail = SITE + '/?p=' + p.id;            // abre la ficha/alerta en el sitio
    const page   = SITE + '/precio.php?id=' + p.id;  // página de historial (SEO)
    const badge  = dealBadge(j.deal);
    const cmp = (p.group_slug && p.group_stores >= 2)
      ? '<div class="oap-cmp-row"><a class="oap-cmp" href="' + SITE + '/producto.php?slug=' + encodeURIComponent(p.group_slug)
        + '" target="_blank" rel="noopener">⚖️ Comparar en ' + p.group_stores + ' tiendas ↗</a></div>'
      : '';
    div.innerHTML =
      '<div class="oap-card">'
      + '<div class="oap-head"><span class="oap-logo">👁️ Ojo al Precio</span><span class="oap-store">' + esc(p.store_name || '') + '</span></div>'
      + (badge ? '<div class="oap-badge-row">' + badge + '</div>' : '')
      + '<div class="oap-chart">' + sparkline(h.map(x => x.price_final)) + '</div>'
      + '<div class="oap-stats">'
      +   '<div><span>Actual</span><b>' + money(s.current, cur) + '</b></div>'
      +   '<div><span>Mínimo</span><b class="oap-min">' + money(s.min, cur) + '</b></div>'
      +   '<div><span>Máximo</span><b class="oap-max">' + money(s.max, cur) + '</b></div>'
      + '</div>'
      + (h.length < 2 ? '<div class="oap-note">📅 Se necesitan más días para ver la tendencia.</div>' : '')
      + cmp
      + '<div class="oap-actions">'
      +   '<a class="oap-btn oap-primary" href="' + detail + '" target="_blank" rel="noopener">🔔 Trackear precio</a>'
      +   '<a class="oap-link" href="' + page + '" target="_blank" rel="noopener">Ver historial ↗</a>'
      + '</div>'
      + '</div>';
  }

  function load(anchor) {
    const div = mount(anchor, shell('<div class="oap-body oap-muted">Cargando historial…</div>'));
    fetch(API + '/history.php?url=' + encodeURIComponent(location.href))
      .then(r => r.json())
      .then(j => j.ok ? renderChart(div, j) : renderTrack(div))
      .catch(() => renderError(div));
  }

  // Ancla del precio. En VTEX preferimos el precio PRINCIPAL (no el de un shelf
  // de "productos relacionados" que puede renderizar antes durante la hidratación).
  function getAnchor() {
    if (CONF.platform !== 'vtex') return document.querySelector(CONF.anchor);
    const all = [...document.querySelectorAll('[class*="sellingPriceValue"]')];
    return all.find(el => !el.closest('[class*="productSummary"], [class*="product-summary"], [class*="shelf"], [class*="Shelf"]'))
        || all[0] || null;
  }

  let processedUrl = '';
  function tick() {
    if (!isProduct()) { removeWidget(); processedUrl = ''; return; }
    if (location.href === processedUrl) return;
    const anchor = getAnchor();
    if (!anchor) return;                 // esperar a que el precio cargue
    processedUrl = location.href;
    load(anchor);
  }

  // SPAs (VTEX) cambian de producto sin recargar → observar + respaldo por intervalo.
  new MutationObserver(() => tick()).observe(document.documentElement, { childList: true, subtree: true });
  setInterval(tick, 1500);
  tick();
})();
