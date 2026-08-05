/* Ojo al Precio — content script.
 * Detecta el producto en Sinsa/Siman/Copasa, consulta la API pública e
 * inyecta una tarjeta con el historial de precios debajo del precio. */
(function () {
  'use strict';

  const API  = 'https://agrotecnicaragua.com/ojoalprecio/api';
  const SITE = 'https://agrotecnicaragua.com/ojoalprecio';

  const host = location.hostname.replace(/^www\./, '');
  const CONF = {
    'sinsa.com.ni': { platform: 'vtex',   anchor: '.vtex-product-price-1-x-sellingPriceValue' },
    'ni.siman.com': { platform: 'vtex',   anchor: '.vtex-product-price-1-x-sellingPriceValue' },
    'copasa.com.ni': { platform: 'copasa', anchor: '.name-product' },
  }[host];
  if (!CONF) return;

  const money = (v, cur) => v == null ? '—'
    : (cur === 'USD' ? '$' : 'C$') + new Intl.NumberFormat('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function isProduct() {
    return CONF.platform === 'vtex'
      ? /-\d+\/p(\/|\?|#|$)/.test(location.pathname)
      : /\/Product\/Detail\//i.test(location.pathname);
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
    const target = CONF.platform === 'vtex'
      ? (anchor.closest('[class*="vtex-product-price"]') || anchor.parentElement || anchor)
      : anchor;
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

  function renderChart(div, j) {
    const p = j.product, s = j.stats, h = j.history, cur = s.currency || 'NIO';
    const link = SITE + '/?p=' + p.id;
    div.innerHTML =
      '<div class="oap-card">'
      + '<div class="oap-head"><span class="oap-logo">👁️ Ojo al Precio</span><span class="oap-store">' + esc(p.store_name || '') + '</span></div>'
      + '<div class="oap-chart">' + sparkline(h.map(x => x.price_final)) + '</div>'
      + '<div class="oap-stats">'
      +   '<div><span>Actual</span><b>' + money(s.current, cur) + '</b></div>'
      +   '<div><span>Mínimo</span><b class="oap-min">' + money(s.min, cur) + '</b></div>'
      +   '<div><span>Máximo</span><b class="oap-max">' + money(s.max, cur) + '</b></div>'
      + '</div>'
      + (h.length < 2 ? '<div class="oap-note">📅 Se necesitan más días para ver la tendencia.</div>' : '')
      + '<div class="oap-actions">'
      +   '<a class="oap-btn oap-primary" href="' + link + '" target="_blank" rel="noopener">🔔 Crear alerta</a>'
      +   '<a class="oap-link" href="' + link + '" target="_blank" rel="noopener">Ver historial completo ↗</a>'
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

  let processedUrl = '';
  function tick() {
    if (!isProduct()) { removeWidget(); processedUrl = ''; return; }
    if (location.href === processedUrl) return;
    const anchor = document.querySelector(CONF.anchor);
    if (!anchor) return;                 // esperar a que el precio cargue
    processedUrl = location.href;
    load(anchor);
  }

  // SPAs (VTEX) cambian de producto sin recargar → observar + respaldo por intervalo.
  new MutationObserver(() => tick()).observe(document.documentElement, { childList: true, subtree: true });
  setInterval(tick, 1500);
  tick();
})();
