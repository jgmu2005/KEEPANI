# Publicación en Chrome Web Store — Ojo al Precio (extensión)

Contacto: **info@ojoalprecio.online** · Política: **https://ojoalprecio.online/privacidad.html**

> ⚠️ **Rechazo "Keyword Spam" (ago-2026)**: Google marcó la lista de nombres de tiendas
> en la descripción detallada como relleno de keywords. Solución: se quitó la lista y se
> reemplazó por una frase genérica ("las principales tiendas… ver lista en el sitio").
> El `manifest` no nombra tiendas (ya cumplía). Editar la descripción en el dashboard y reenviar.

---

## Ficha de la tienda (copiar/pegar)

**Nombre**
```
Ojo al Precio — Historial de precios de Nicaragua
```

**Descripción corta** (máx. 132 caracteres)
```
Mirá el historial de precios, si es buen precio y en qué otras tiendas está — directo en la página del producto.
```

**Categoría:** Compras (Shopping)
**Idioma:** Español (Latinoamérica)

**Descripción detallada**
```
Ojo al Precio te muestra el historial de precios de un producto directo en la página de la tienda, para que sepas si el precio de hoy es bueno o no antes de comprar.

Cuando entrás a un producto en una tienda compatible, aparece una tarjeta con:
• El precio actual, el más bajo y el más alto que registramos.
• Un mini-gráfico de la tendencia del precio.
• Un aviso si está en su precio más bajo, o si el "descuento" es poco fiable.
• Un botón para comparar el mismo producto en otras tiendas.
• La opción de crear una alerta y que te avisemos por correo cuando baje.

Funciona en las principales tiendas de electrónica, hogar y electrodomésticos de Nicaragua. Podés ver la lista actualizada de tiendas compatibles en ojoalprecio.online.

Privacidad: la extensión sólo lee la URL de la página de producto que estás viendo para traer su historial desde ojoalprecio.online. No recolecta datos personales, no rastrea tu navegación ni lee otras pestañas.

Los precios son referenciales; verificá siempre el precio final en la tienda.
```

---

## Práctica única / permisos (los pide el formulario de revisión)

**Propósito único (single purpose):**
```
Mostrar el historial de precios de un producto en la página de la tienda que el usuario está viendo, y permitir compararlo o crear una alerta.
```

**Justificación de `host_permissions` (ojoalprecio.online):**
```
La extensión consulta nuestra API pública en ojoalprecio.online para obtener el historial de precios del producto que el usuario está viendo.
```

**Justificación de los content scripts (las 9 tiendas):**
```
El content script se ejecuta sólo en las páginas de producto de las tiendas soportadas para detectar el producto e inyectar la tarjeta de historial debajo del precio.
```

**¿Usa código remoto?** No.
**¿Recolecta datos de usuario?** No recolecta datos personales. Se envía únicamente la URL del producto para obtener su historial.

---

## Assets a subir

- **Ícono 128×128**: `icons/icon-128.png` (ya incluido).
- **Screenshots 1280×800** (1 a 5, PNG/JPG): capturas del widget en una página de producto real
  (ej. la de La Curacao con el comedor). Que se vea la tarjeta con badge + comparar + botones.
- **Mosaico pequeño 440×280** (opcional pero recomendado): banner simple con el logo y el nombre.

---

## Paso a paso

1. Crear cuenta de desarrollador en https://chrome.google.com/webstore/devconsole (pago único US$5).
2. Subir `ojo-al-precio-extension.zip` (ver README de empaquetado).
3. Completar la ficha con los textos de arriba.
4. Subir ícono + screenshots.
5. En "Privacy practices": pegar el propósito único y las justificaciones; enlazar la política
   (https://ojoalprecio.online/privacidad.html) y el correo de contacto (info@ojoalprecio.online).
6. Enviar a revisión (suele tardar de horas a unos días).

> Nota: mantené `manifest.json` → `version` subiendo (0.3.0, 0.3.1, …) en cada actualización.
