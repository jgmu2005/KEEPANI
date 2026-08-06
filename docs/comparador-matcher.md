# Comparador cross-store — bosquejo del matcher

> Objetivo: decidir que "el mismo producto" vendido en varias tiendas es UNA
> sola cosa, para armar la **página pública por producto** (SEO) con el
> **comparador de precios** y el **botón de compartir**. Esto es el corazón de
> la fase #3.

## 1. Panorama de identificadores (según recon 2026-08)

| Tienda | Plataforma | ID fuerte disponible | Notas |
|---|---|---|---|
| Siman, Sinsa | VTEX | ✅ **EAN/GTIN** (`items[].ean`) + `brand` + `productReference` | Match perfecto entre tiendas VTEX |
| RadioShack, La Curacao, Tropigas | Unicomer (Magento) | ✅ **ID Unicomer compartido** (id final de la URL `…-{id}/p`), `brand`, título | Sin EAN, pero el id se comparte entre hermanas: **1341/1428 SKUs de RadioShack también están en Tropigas (94%)** → match automático dentro de la familia |
| El Gallo | Magento (OG) | `brand`, título | EAN por confirmar |
| Copasa | OG | `brand`, título | Sin EAN |

**Conclusión clave:** hay 3 "islas" de identidad fuerte —
(a) EAN entre las VTEX, (b) SKU compartido dentro de Unicomer — pero **el cruce
ENTRE islas** (ej. iPhone de Siman vs RadioShack) no tiene ID común y exige
match por **marca + modelo + título + precio**.

## 2. Estrategia por niveles (de mayor a menor confianza)

- **Nivel A — EAN/GTIN igual** → confianza 1.0, auto. (VTEX↔VTEX; y cualquiera
  que logremos enriquecer con EAN a futuro.)
- **Nivel A' — SKU Unicomer igual** → confianza 1.0, auto, *solo dentro de la
  familia Unicomer*.
- **Nivel B — marca + firma de modelo** → ~0.9. Extraer del título una firma
  normalizada (ej. `apple|iphone-16-pro-max|256gb`, o el código de modelo de
  electrodomésticos tipo `WT23PBTX6A`). Muy fiable en electro/línea blanca.
- **Nivel C — título difuso** → 0.6–0.85. Marca igual + similitud de tokens del
  título (Jaccard / trigramas) + precio dentro de ±X% → **cola de revisión
  manual** (no auto).
- **Nivel D — imagen (PROTAGONISTA, no "a futuro")** → hash perceptual (**dHash**
  de la foto; las tiendas reusan la imagen del fabricante. El caso Remington
  (§10) mostró que suele ser **la señal más fuerte disponible** cuando falta el
  EAN. Más pesado (hay que bajar y hashear imágenes), pero decisivo.
  - **Validado empíricamente** (§10): la MISMA secadora en Siman vs Sinsa dio
    dHash = **11**; el no-match más cercano = **19**. → **umbral ~13-14** con
    buen margen. Usar **dHash** (separó mejor que aHash: 10 vs 4 en un par confuso).
  - Es un **rankeador de candidatos** (vecino más cercano dentro del bloque de
    marca), no un juez único: combinar con marca + atributos para no fusionar
    productos de silueta parecida (una plancha y un cepillo dieron 10 entre sí).
  - **Cómputo**: dHash de 64 bits (redimensionar a 9×8, gris, comparar píxeles
    contiguos). Se calcula en el crawler (GitHub Actions, PHP GD) y se guarda
    como `img_dhash` (BIGINT/CHAR(16) hex); el match compara por Hamming en el
    batch (XOR + popcount), no en SQL.

### Guardarraíl obligatorio: atributos discriminantes
Marca+título NO alcanza para fusionar: hay que **extraer y exigir coincidencia**
de atributos que separan productos distintos de la misma línea —
**potencia (watts), capacidad, subtipo** (secadora ≠ cepillo-secadora ≠
plancha). Sin esto se fusionan productos distintos (ver §10).

### Diccionario de sinónimos / traducción
`zafiro=sapphire`, y ES/EN en general. Sin esto, el mismo modelo en dos idiomas
baja de similitud y no matchea.

## 3. Blocking (para no comparar todo contra todo)

Comparar N productos de a pares es O(N²). Antes de comparar, **agrupar en
cubetas** por una clave barata y solo comparar dentro de la cubeta:

- Clave de bloque = `marca` + primer token de modelo (o categoría).
- Reduce millones de comparaciones a miles. Corre en batch, no por request.

## 4. Modelo de datos (evolución del esquema)

Hoy existe `product_matches` (pares). Para "N tiendas venden lo mismo" conviene
un concepto de **grupo canónico**:

```
product_groups
  id, slug (para la URL /producto/{slug}), canonical_title, brand,
  ean NULL, image_url, created_at

products
  + group_id  BIGINT NULL   (a qué grupo pertenece; NULL = aún sin agrupar)
  + ean            VARCHAR NULL   (enriquecido en ingesta)
  + model_norm     VARCHAR NULL   (firma de modelo normalizada)
  + brand_norm     VARCHAR NULL   (marca normalizada para blocking)

match_review            (cola de confianza media)
  id, product_id, group_id, confidence, method, status('pending'|'ok'|'no')
```

`product_matches` (pares) queda como señal cruda; los **grupos** son el
resultado clusterizado (componentes conexos de los pares de alta confianza) y
lo que consulta la página pública.

## 5. Pipeline (dónde corre)

Igual que los crawlers: **batch en GitHub Actions**, después del crawl diario.

1. **Enriquecer identidad en la ingesta** (empezar ya, para acumular):
   - VtexMapper: capturar `items[].ean`.
   - Unicomer: capturar el SKU de Unicomer como `ean`-equivalente de familia.
   - Todos: derivar `brand_norm` y `model_norm` del título/marca.
2. **Matcher batch**: Nivel A/A' → agrupa automático; Nivel B → auto o
   revisión según umbral; Nivel C → cola `match_review`.
3. **Panel admin**: confirmar/rechazar los de confianza media (1 clic).
4. Regenerar `slug` y sitemap de grupos.

## 6. Normalización (pipeline de texto)

`lower` → quitar acentos → quitar ruido (`nicaragua`, nombre de tienda,
"reacondicionado" salvo que importe) → normalizar unidades (`256 gb`→`256gb`,
`55"`→`55in`) → extraer marca y firma de modelo. El **color** se trata como
atributo (no separa grupo); la **capacidad/modelo** sí separa.

## 7. Página pública (el entregable de #3)

- `producto.php?slug=...` **server-rendered** (no SPA) para SEO real: `<title>`,
  `<meta description>`, **JSON-LD** `Product` con `offers[]` (una por tienda) y
  `lowPrice`/`highPrice`.
- Comparador: tabla "tienda · precio · stock · ver", ordenada por precio.
- Compartir: enlace `wa.me/?text=...` + imagen OG.
- Sitemap XML de todas las URLs de grupo → indexación en Google.

## 8. Fases sugeridas

1. **Enriquecer identidad en ingesta** (EAN VTEX + SKU Unicomer + brand/model
   normalizados). *Empezar pronto: necesita acumular datos.*
2. Matcher batch A/A' (auto, alta confianza) + tabla `product_groups`.
3. Página pública `producto.php` + JSON-LD + compartir + sitemap.
4. Nivel B/C + panel de revisión admin.
5. (Futuro) hash de imagen; enriquecer EAN de Unicomer para cruzar islas.

## 10. Caso real: secadora Remington (Siman vs Sinsa)

Prueba con dos productos idénticos (misma secadora Remington Sapphire/Zafiro
Luxe 2200W):

| Señal | Siman | Sinsa | ¿Sirvió? |
|---|---|---|---|
| EAN | `""` (vacío) | `74590557671` | ❌ Siman no lo cargó |
| Marca | Remington | Remington | ✅ |
| Tipo | secadora de cabello | secadora de cabello | ✅ |
| Modelo | **Zafiro** Luxe 2200w | **Sapphire** Luxe | ⚠️ sinónimo ES/EN |
| Título (Jaccard) | — | — | ⚠️ ≈0.50 → revisión |
| Imagen | foto fabricante | misma foto (otro host) | ✅ **la más fuerte** |
| Precio | C$2,599 | C$3,029 | ✅ +16.5% |

**Conclusiones:** (1) EAN no confiable ni en VTEX; (2) título solo = confianza
media por sinónimos; (3) el pHash de imagen es la señal ganadora; (4) cuidado
con falsos positivos: Siman también tiene "Cepillo Secadora Zafiro Luxe **1000w**"
y "Plancha Sapphire Luxe" → sin guardarraíl de atributos (watts/subtipo) se
fusionarían mal.

**Prueba de pHash (dHash, distancia de Hamming):**

| | Secadora Siman | Secadora Sinsa | Cepillo 1000w | Plancha |
|---|---|---|---|---|
| Secadora Siman | 0 | **11** | 20 | 22 |
| Secadora Sinsa | **11** | 0 | 19 | 23 |
| Cepillo 1000w | 20 | 19 | 0 | 10 |
| Plancha | 22 | 23 | 10 | 0 |

El verdadero match (11) es el vecino más cercano y queda claramente por debajo
de los no-match (19-23). Umbral sugerido ~13-14. El par plancha/cepillo (10)
confirma que el pHash necesita el guardarraíl de marca+atributos.

## 9. Preguntas abiertas

- ✅ **RESUELTO**: el id de Unicomer SÍ se comparte entre hermanas
  (RadioShack↔Tropigas: 94% de coincidencia). Falta confirmar La Curacao.
- ¿Gallo expone EAN en su ficha o specs?
- ¿Qué % de EANs de Siman coinciden con Sinsa (para dimensionar el Nivel A)?
- ¿Agrupamos variantes de color juntas (recomendado) o separadas?
