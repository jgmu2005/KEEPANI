# Ojo al Precio — Roadmap / Backlog de mejoras

Prioridad: 🔴 importante (antes de abrir a usuarios reales) · 🟡 alto valor · 🟢 nice-to-have
Esfuerzo: S (chico) · M (medio) · L (grande)

---

## 🗓️ Roadmap — mejoras, ajustes y pendientes (semana del 11–15 ago 2026)

✅ Ya hecho: deploy de todo (SEO gate, marketplace, celulares, refresh) + ops (migraciones 027/028, Search Console + sitemap, crons, workflow marketplace, cron FatCow del marketplace de baja).

### A. Verificar / cabos sueltos 🔴 S
- [ ] **Eliminar cuenta** (perfil): endpoint `api/account/delete.php` (requireUser + confirmar contraseña) que borra al usuario y sus datos (alerts, etc.) + botón en "Mi cuenta". Rápido y es buena práctica/requisito GDPR. **Prioridad alta.**
- [ ] **Marketplace `tienda.treinta.co`** (multitiendaonlinemanagua): confirmar que el crawler nuevo le trae productos; si sigue trayendo pocos, darla de baja o dejarla como está.
- [ ] Confirmar que el workflow de GitHub trajo los **catálogos completos** (ej. Rogama ~663) y que se ve bien en `/marketplace`.
- [ ] **Ko-fi end-to-end** de nuevo (un pago + suscripción) tras todos los cambios → verificar auto-upgrade a onetime/subscriber.
- [ ] **Mobile**: repasar el home, ficha `precio.php`, comparador y marketplace en celular (hubo un reporte viejo de "no es mobile-friendly").

### B. SEO (alto valor) 🟡
- [ ] **Hubs por categoría** `/categoria/{key}` — página única "Precios de {categoría} en Nicaragua" con sus productos. Es la mejor jugada SEO nueva (contenido único + capta long-tail). (M)
- [ ] **Enlazado interno**: breadcrumbs y links precio ↔ producto ↔ hubs de categoría (ayuda al crawl y al ranking). (S)
- [ ] Más "carne" en `precio.php`: texto único ("¿es buen precio?", contexto) para bajar aún más la thinness. (S)
- [ ] Imagen OG por defecto (`seo_default_image`) para que se vea lindo al compartir. (S)
- [ ] Iterar según Search Console (qué indexa, qué queda fuera).

### C. Marketplace 🟡🟢
- [ ] **Buscador por nombre** dentro de `/marketplace`. (S)
- [ ] Sumar más tiendas Treinta desde el admin. (S)
- [ ] Filtro por **rango de precio** (min–máx) además del orden. (S)
- [ ] Mini-variación/sparkline por producto cuando haya historial. (S)

### D. Comparador / datos 🟡🟢
- [ ] Más comparaciones cross-store: correr `hash` + `match` periódicos y **revisar la cola de matches** en admin. (S, recurrente)
- [ ] Sumar más tiendas al tracker. (M)
- [ ] Categorías con **IA** — clasificar el 100% + mejores títulos SEO. (L, futuro)

### E. Premium / negocio 🟡🟢
- [ ] **Empujar Premium**: ahora que `refresh_tracked` da valor real (6×/día), revisar que la landing/paywall lo comunique bien. (S)
- [ ] Eventos en GA4 para medir conversión (clic en "Hacete Premium", crear alerta). (S)

### E2. Perks para el suscriptor mensual (ideas priorizadas) 🟡🟢
Hoy tiene: alertas ilimitadas · refresco 6×/día · liquidaciones Walmart por email.
- [ ] **Alertas por WhatsApp** (además de email) — killer en NI donde WhatsApp manda. (M/L — requiere API WhatsApp/Twilio, tiene costo)
- [ ] **Liquidaciones multi-tienda** — extender el cazaofertas ≥30% a Sinsa/Siman/etc. (subscriber ve el feed de todas). (M, reusa el patrón wm_*)
- [ ] **Alertas instantáneas** vs digest — el suscriptor recibe apenas se detecta; free/onetime en resumen. (S)
- [ ] **Reporte semanal personalizado** — "tus productos esta semana: X bajó, Y subió". (S/M)
- [ ] **Señal "¿comprar o esperar?"** — indicador buy/wait por producto según su historial (perk exclusivo o más detallado). (M)
- [ ] **Alerta por % / vs histórico** — "avisame si baja 20% del promedio", no solo precio fijo. (S)
- [ ] **Export CSV / historial completo** del producto (free ve ventana corta). (S)
- [ ] **Alerta de brecha entre tiendas** — "este producto está 30% más barato en otra tienda". (S, reusa comparador)

### F. Reliability / ops 🟢
- [ ] Aviso si **falla un crawl** (notificación de GitHub Actions). (S)
- [ ] **Backup** periódico de la BD. (S)
- [ ] Retención/prune de `price_history` (~76k filas/día crecen rápido). (M, futuro)

### G. Admin — panel de stats 🟡 (para cuando crezca)
Endpoint `api/admin/stats.php` + sección en admin.html con: usuarios por nivel (free/onetime/subscriber) y nuevos por semana · alertas activas · notificaciones enviadas · donaciones/conversión · tamaño de catálogo por tienda y # de puntos de precio · productos más rastreados · liquidaciones detectadas · cola de matches pendientes · salud de crawls (última corrida por tienda). (M)

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
