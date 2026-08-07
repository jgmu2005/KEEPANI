# Ojo al Precio — Roadmap / Backlog de mejoras

Prioridad: 🔴 importante (antes de abrir a usuarios reales) · 🟡 alto valor · 🟢 nice-to-have
Esfuerzo: S (chico) · M (medio) · L (grande)

---

## 🗓️ Cierre — semana del 11–15 ago 2026

Objetivo: dejar **desplegado, verificado y monitoreado** todo lo construido (refresco Premium, comparador de celulares, SEO indexable, marketplace Treinta) — sin features grandes nuevos.

### 1. Deploy pendiente (SFTP) — primero 🔴 S
- [ ] Gate SEO: `web/precio.php`, `web/sitemap.php`, `web/sitemap_productos.php`
- [ ] Orden marketplace: `web/marketplace.php`, `web/src/Marketplace/MarketplaceRepo.php`
- [ ] Confirmar que ya estén arriba: `mk_ingest.php`, `Verification.php` (fix baseUrl), `CategoryClassifier.php`, `Seo.php`, `PriceChart.php`, `index.html`, `admin.html`
- [ ] Migraciones corridas: **027** (cat_key) y **028** (marketplace)

### 2. Ops / crons 🔴 S
- [ ] **Google Search Console**: verificar dominio (DNS TXT o archivo HTML) + enviar `sitemap.xml`
- [ ] Marketplace: confirmar que el workflow de GitHub trajo catálogos completos → **DESACTIVAR el cron FatCow `crawl_marketplace.php`** (si no, borra todo menos 12) → dejar sólo el workflow diario
- [ ] cron-job.org: confirmar `refresh_tracked.php?tiers=subscriber` (cada 4h) + `?tiers=onetime` (2×/día) + `alerts_check.php`
- [ ] Encadenar/ordenar los crons de comparador: `build_groups` → `build_phone_groups` → `build_categories`

### 3. Monitoreo (durante la semana) 🟡 S
- [ ] GA4: primeras visitas / fuentes
- [ ] Search Console: cobertura (cuántas fichas indexa), errores de rastreo
- [ ] Ver que las fichas "maduren" y entren solas al sitemap (gate por historial)

### 4. Pulido si hay tiempo 🟢 S/M
- [ ] Marketplace: **buscador por nombre** dentro de `/marketplace` (S)
- [ ] Sumar más tiendas Treinta desde el admin (S)
- [ ] Revisar la **cola de matches** en admin para sumar comparaciones cross-store (S)
- [ ] **Hubs por categoría** (una página SEO por categoría con sus productos) — joya SEO (M)

### 5. Backlog / futuro (NO esta semana)
- Clasificación de categorías con **IA** (100% del catálogo + mejores títulos SEO)
- Marketplace: landing SEO por tienda si tracciona; catálogo completo estable
- Más tiendas del tracker

---

## Ya hecho ✅
3 tiendas (Sinsa, Siman, Copasa) · ~5,000 productos · seed masivo · refresco diario (GitHub Actions) · dashboard con gráficas · buscador + filtros combinados + paginación · alta on-demand · cuentas · panel admin (branding, SMTP, usuarios) · alertas de precio por email.

## En cola (acordado)
1. **Extensión de Chrome** (siguiente)
2. **Webhook de Ko-fi** (auto-upgrade a Donante)

---

## 1. Confianza / "listo para usuarios reales"
- 🔴 S — **Recuperar contraseña** ("olvidé mi contraseña" por email).
- 🔴 S — **Verificación de email** en el registro (doble opt-in) para no mandar alertas a correos inválidos y evitar abuso.
- 🔴 S — **Link de "desuscribirse"** en los emails de alerta (buena práctica y anti-spam).
- 🔴 S — **Política de privacidad + Términos** (manejás correos y contraseñas).
- 🔴 S — **Rate limiting** en login, registro, **recuperación de contraseña** y track (límites por IP + por cuenta).
- 🔴 M — **Anti-abuso de registro** (que no revienten la BD con cuentas basura): verificación de email obligatoria antes de activar, **CAPTCHA/Cloudflare Turnstile** en registro y recuperación, bloqueo de **dominios de correo desechables**, límite de registros por IP/hora, y honeypot.
- 🟡 S — **Protección CSRF** en los POST con sesión (ya hay SameSite=Lax, que mitiga; token lo cierra).
- 🟢 M — 2FA opcional para el admin.

## 2. Cobertura de datos
- 🟡 L — **Más tiendas** (adaptadores ya diseñados en el recon): Comtech (API .NET), El Gallo más Gallo (Magento), La Curacao + Tropigas (Magento Unicomer, requieren Playwright), PriceSmart (commercetools, +IVA).
- 🟡 M — **Crawl por categorías** para pasar el tope de 2,500/tienda VTEX (Sinsa tiene ~50k, Siman ~18k).
- 🟡 M — **Categoría/taxonomía por producto** → filtro "por categoría" en el dashboard.
- 🟢 M — Más supermercados/retail NI (La Colonia, Maxi Palí, Cemaco, Conico, etc.).
- 🟢 M — **Variaciones** (talla/color) — tabla product_variations ya prevista.

## 3. Alertas y notificaciones
- 🟡 M — **Refrescar los productos rastreados ≥4 veces/día** (no todo el catálogo — solo los que tienen alertas activas): un cron cada ~6h que actualiza esos productos para detectar bajadas más rápido y disparar alertas casi en el día.
- 🟡 S — **Tipos de alerta**: "baja X%", "está en su mínimo histórico", "volvió a haber stock".
- 🟡 M — **WhatsApp / Telegram** como canal (WhatsApp es enorme en NI).
- 🟡 S — **Resumen semanal** por email ("mejores bajadas de la semana").
- 🟢 S — Push del navegador (web push).
- 🟢 S — Historial de disponibilidad (stock) además de precio.

## 4. Dashboard / ficha del producto
- 🟡 S — **Badge "precio más bajo histórico"** (estilo Keepa) + marcar mín/máx en la gráfica.
- 🟡 S — **Rango de tiempo** en la gráfica (30d / 90d / 1año / todo).
- 🟡 S — **Página propia por producto** (`/p/{id}`) para compartir y SEO.
- 🟡 S — Mostrar **precio de lista tachado** y % de descuento en la gráfica.
- 🟢 S — **Modo oscuro** + **PWA** (instalable en el celular).
- 🟢 S — "Buen momento para comprar" (precio cerca del mínimo).
- 🟢 S — Favoritos/seguir sin alerta.

## 5. Monetización
- 🟡 S — **Niveles extra** (ej. 25 / ilimitado por más donación).
- 🟡 M — **Membresía recurrente** de Ko-fi (mantener Donante mientras done).
- 🟡 S — **Vencimiento del nivel** Donante (ej. renovar cada X meses).
- 🟢 M — Afiliados (si las tiendas lo ofrecen) / banner patrocinado.

## 6. SEO / crecimiento
- 🟡 M — **Páginas de producto indexables** (SEO → tráfico orgánico gratis).
- 🟡 S — **Open Graph por producto** (compartir en redes con imagen + precio).
- 🟡 S — **Sitemap** + robots.txt.
- 🟢 M — Sección "Ofertas del día" / blog.
- 🟢 S — Botones de compartir en redes.

## 7. Admin / operaciones
- 🟡 S — **Métricas** en el admin (usuarios, alertas, emails enviados, cobertura por tienda, salud de scrapers).
- 🟡 S — **Gestión de tiendas** desde el admin (activar/desactivar/agregar).
- 🟡 S — **Monitoreo de scrapers**: avisar si una tienda falla N días seguidos.
- 🟢 S — Ver/borrar productos basura, fusionar duplicados.
- 🟢 S — Ver notificaciones enviadas / donaciones recibidas.

## 8. Rendimiento / escala
- 🟡 S — **Denormalizar el último precio** en `products` (columna last_price) → búsquedas/filtros más rápidos al crecer.
- 🟢 S — Guardar histórico solo cuando **cambia** el precio (ahorra filas).
- 🟢 S — Índices adicionales + caché de listados/ajustes.
- 🟢 S — Marcar productos **descontinuados** (fuera del catálogo N días → inactivo).

## 9. Robustez del scraping
- 🟡 S — Reintentos + backoff ya hay; agregar **alertas de fallo** al admin.
- 🟢 M — Cachear/optimizar imágenes.
- 🟢 S — Deduplicar productos con varias URLs.
