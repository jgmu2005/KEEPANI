// Crawler del marketplace Treinta para GitHub Actions (navegador headless).
// Abre cada tienda, deja que su propio JS pagine (scroll infinito) y captura el
// JSON del server-action; junta TODO el catálogo y lo postea a /api/mk_ingest.php.
//
// Robusto: como usa el navegador real, se auto-recupera si Treinta redespliega
// (no dependemos del id del server-action ni de un formato de request).
//
// Env: OJO_INGEST_URL (…/api/ingest.php → derivamos mk_ingest.php), OJO_INGEST_KEY
//      STORE (opcional: un slug puntual)

import { chromium } from 'playwright';

const INGEST = process.env.OJO_INGEST_URL || '';
const KEY    = process.env.OJO_INGEST_KEY || '';
const ONLY   = (process.env.STORE || '').trim();
if (!INGEST || !KEY) { console.error('Faltan OJO_INGEST_URL / OJO_INGEST_KEY'); process.exit(1); }
const MK = INGEST.replace(/\/[^/]*$/, '/mk_ingest.php');

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// --- Normalización de un producto (de cualquier fuente) ---
function norm(o) {
  let price = o.price;
  if (Array.isArray(price)) { price = Math.min(...price.map(Number).filter(x => x > 0)); }
  price = Number(price);
  const name = String(o.name ?? o.title ?? '').trim();
  const id   = String(o.id ?? o.ext_id ?? '').trim();
  if (!name || !(price > 0)) return null;
  return {
    ext_id: id || 'n-' + Buffer.from(name).toString('hex').slice(0, 24),
    name,
    price,
    image_url: o.imageUrl || o.image || o.image_url || '',
    in_stock: (o.isVisible === 1 || o.in_stock) ? 1 : 0,
    currency: o.priceCurrency || o.currency || 'NIO',
  };
}

// --- Parser del "flight data" del HTML (página 1 SSR) ---
function fromFlight(html) {
  const s = html.replaceAll('\\"', '"').replaceAll('\\\\', '\\').replaceAll('\\/', '/');
  const re = /"id":"[0-9a-f-]{36}"/g; const idx = []; let m;
  while ((m = re.exec(s))) idx.push(m.index);
  const out = [];
  for (let i = 0; i < idx.length; i++) {
    const chunk = s.slice(idx[i], i + 1 < idx.length ? idx[i + 1] : idx[i] + 3000);
    if (!/"isVisible":1/.test(chunk)) continue;
    const mp = /"price":(\[[0-9.,]+\]|[0-9.]+)/.exec(chunk); if (!mp) continue;
    const mn = /"name":"((?:[^"\\]|\\.)*?)"/.exec(chunk);
    const mi = /"imageUrl":(?:"((?:[^"\\]|\\.)*?)"|null)/.exec(chunk);
    const mid = /"id":"([0-9a-f-]{36})"/.exec(chunk);
    let price = mp[1]; if (price[0] === '[') price = JSON.parse(price);
    const p = norm({ id: mid[1], name: mn ? mn[1] : '', price, imageUrl: mi && mi[1] ? mi[1] : '', isVisible: 1 });
    if (p) out.push(p);
  }
  return out;
}

// --- Parser JSON-LD (tienda.treinta.co) ---
function fromJsonLd(html) {
  const re = /"name":"((?:[^"\\]|\\.)*?)","offers":\{"@type":"Offer","price":"([0-9.]+)","priceCurrency":"([A-Z]{3})","availability":"([^"]*)"/g;
  const out = []; let m;
  while ((m = re.exec(html))) {
    const p = norm({ name: m[1], price: Number(m[2]), currency: m[3], in_stock: /InStock/.test(m[4]) ? 1 : 0 });
    if (p) out.push(p);
  }
  return out;
}

// --- Parser de la respuesta del server-action (páginas 2..N) ---
function fromAction(text) {
  const out = []; let hasNext = null;
  for (const line of text.split('\n')) {
    const body = line.replace(/^\d+:/, '');
    if (!body.includes('"data"')) continue;
    try {
      const obj = JSON.parse(body);
      if (obj && Array.isArray(obj.data)) {
        for (const o of obj.data) { const p = norm(o); if (p) out.push(p); }
        if (typeof obj.hasNextPage === 'boolean') hasNext = obj.hasNextPage;
      }
    } catch { /* línea no-JSON del stream RSC */ }
  }
  return { items: out, hasNext };
}

async function crawlStore(browser, store) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 1400 } });
  const map = new Map();
  let hasNextPage = true;

  page.on('response', async (resp) => {
    try {
      const req = resp.request();
      if (req.method() !== 'POST') return;
      if (!resp.url().includes(new URL(store.url).pathname)) return;
      const text = await resp.text();
      const { items, hasNext } = fromAction(text);
      for (const p of items) map.set(p.ext_id, p);
      if (hasNext !== null) hasNextPage = hasNext;
    } catch { /* ignore */ }
  });

  try {
    await page.goto(store.url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleep(2500);
    // Página 1 (SSR) + fallback JSON-LD.
    const html0 = await page.content();
    for (const p of fromFlight(html0)) map.set(p.ext_id, p);
    for (const p of fromJsonLd(html0)) if (!map.has(p.ext_id)) map.set(p.ext_id, p);

    // Scroll infinito hasta que no haya más páginas (o no crezca).
    let stale = 0;
    for (let i = 0; i < 80 && hasNextPage && stale < 4; i++) {
      const before = map.size;
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight * 20);
        document.querySelectorAll('*').forEach(el => {
          if (el.scrollHeight > el.clientHeight + 300) el.scrollTop = el.scrollHeight;
        });
      });
      await sleep(1300);
      stale = (map.size > before) ? 0 : stale + 1;
    }

    const storeName = await page.title().then(t => t.replace(/\s*[|·].*/, '').trim()).catch(() => null);
    const products = [...map.values()];
    return { products, storeName };
  } finally {
    await page.close();
  }
}

async function postBatch(store, storeName, products) {
  const res = await fetch(MK, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': KEY },
    body: JSON.stringify({ store, store_name: storeName, products }),
  });
  const j = await res.json().catch(() => ({}));
  return { status: res.status, body: j };
}

(async () => {
  // Lista de tiendas desde el server.
  const listRes = await fetch(MK, { headers: { 'X-Api-Key': KEY } });
  const list = await listRes.json();
  if (!list.ok) { console.error('No se pudo listar tiendas:', list); process.exit(1); }
  let stores = list.stores;
  if (ONLY) stores = stores.filter(s => s.slug === ONLY);
  console.log(`Tiendas a crawlear: ${stores.length}`);

  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const summary = [];
  for (const s of stores) {
    try {
      const { products, storeName } = await crawlStore(browser, s);
      const r = await postBatch(s.slug, storeName || s.name, products);
      console.log(`[${s.slug}] productos=${products.length} → ingest ${r.status} ${JSON.stringify(r.body)}`);
      summary.push({ store: s.slug, found: products.length, ingest: r.body });
    } catch (e) {
      console.error(`[${s.slug}] ERROR: ${e.message}`);
      summary.push({ store: s.slug, error: e.message });
    }
  }
  await browser.close();
  console.log('RESUMEN:', JSON.stringify(summary, null, 2));
})();
