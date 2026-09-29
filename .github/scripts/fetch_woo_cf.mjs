// Baja el catálogo de un WooCommerce Store API que está detrás de Cloudflare
// ("Just a moment..."), usando un navegador REAL (Playwright) que pasa el challenge
// JS. Luego, YA con la cookie cf_clearance puesta, pagina el Store API desde el
// contexto de la página (fetch mismo-origen) y vuelca el JSON CRUDO a un archivo.
//
// El mapeo/ingesta lo hace PHP después (web/cli/ingest_woo_json.php con WooMapper),
// así no duplicamos la lógica de normalización.
//
// Uso:  node fetch_woo_cf.mjs
// Env:  STORE_URL  (base, ej. https://etech.com.ni)
//       OUT        (archivo de salida, ej. etech_raw.json)

import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const BASE = (process.env.STORE_URL || '').replace(/\/+$/, '');
const OUT  = process.env.OUT || 'woo_raw.json';
const PER_PAGE = 100;
if (!BASE) { console.error('Falta STORE_URL'); process.exit(1); }

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// Trae UNA página del Store API desde el contexto de la página (aplica cf_clearance).
async function apiPage(page, n) {
  return await page.evaluate(async ({ n, per }) => {
    try {
      const r = await fetch(`/wp-json/wc/store/v1/products?per_page=${per}&page=${n}`, {
        headers: { Accept: 'application/json' },
      });
      const totalPages = parseInt(r.headers.get('x-wp-totalpages') || '0', 10);
      const total = parseInt(r.headers.get('x-wp-total') || '0', 10);
      let body = null;
      if (r.status === 200) { try { body = await r.json(); } catch { body = null; } }
      return { status: r.status, totalPages, total, body };
    } catch (e) {
      return { status: 0, totalPages: 0, total: 0, body: null, err: String(e) };
    }
  }, { n, per: PER_PAGE });
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-blink-features=AutomationControlled'] });
  const ctx = await browser.newContext({ userAgent: UA, locale: 'es-NI', viewport: { width: 1366, height: 900 } });
  const page = await ctx.newPage();

  console.error(`▶ abriendo ${BASE} (pasando Cloudflare)…`);
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => {});

  // Esperar a que Cloudflare deje pasar: reintentar la página 1 del API hasta 200.
  // Cada tanto recargamos para re-disparar el challenge JS.
  let first = null;
  for (let t = 0; t < 12; t++) {
    const p = await apiPage(page, 1);
    if (p.status === 200 && Array.isArray(p.body)) { first = p; break; }
    console.error(`  … esperando challenge (intento ${t + 1}/12, status ${p.status})`);
    await sleep(3000);
    if (t === 3 || t === 7) { await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => {}); }
  }
  if (!first) {
    console.error('✖ no se pudo pasar el challenge de Cloudflare (¿CAPTCHA duro?). Abortando.');
    await browser.close();
    process.exit(2);
  }

  const totalPages = Math.max(1, first.totalPages || 1);
  console.error(`  ✔ challenge pasado · total=${first.total} · páginas=${totalPages}`);
  const all = [...first.body];

  for (let n = 2; n <= totalPages; n++) {
    let p = null;
    for (let t = 0; t < 4; t++) {
      p = await apiPage(page, n);
      if (p.status === 200 && Array.isArray(p.body)) break;
      await sleep(1500 + Math.random() * 800);
    }
    if (p && Array.isArray(p.body)) { all.push(...p.body); }
    else { console.error(`  ⚠ página ${n} no bajó (status ${p ? p.status : '?'})`); }
    if (n % 10 === 0) console.error(`  … ${n}/${totalPages} páginas · ${all.length} productos`);
    await sleep(300 + Math.random() * 300);
  }

  await browser.close();
  writeFileSync(OUT, JSON.stringify(all));
  console.error(`✔ ${all.length} productos crudos → ${OUT}`);
})().catch((e) => { console.error('ERROR:', e); process.exit(1); });
