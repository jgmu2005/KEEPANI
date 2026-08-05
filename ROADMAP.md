# Ojo al Precio — Roadmap / Backlog de mejoras

Prioridad: 🔴 importante (antes de abrir a usuarios reales) · 🟡 alto valor · 🟢 nice-to-have
Esfuerzo: S (chico) · M (medio) · L (grande)

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
