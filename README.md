# Ojo al Precio

Tracker de precios estilo Keepa para e-commerce de Nicaragua.
Historial de precios, alertas de bajada y comparador entre tiendas.

> **Hosting actual:** subdirectorio temporal `ojoalprecio.online`
> mientras se compra el dominio propio. El código no asume el subpath (rutas
> relativas), así que migrar = mover la carpeta y actualizar la URL del cron.

## Estructura

```
db/schema.sql              Esquema MySQL (ejecutar 1 vez)
web/                       TODO el app -> va en public_html/ojoalprecio/
  config/config.sample.php   Plantilla de credenciales -> copiar a config.php
  config/.htaccess           Bloquea acceso web a las credenciales
  src/Db.php                 Conexión PDO
  src/IngestService.php      Upsert de producto + insert de histórico
  src/Fetch/                 Librería de scraping (cURL): Http, adaptadores VTEX/OG
  src/.htaccess              Protege el código fuente
  api/ingest.php             POST endpoint (recibe batches externos; auth X-Api-Key)
  cron/run.php               Runner diario que dispara cron-job.org (auth X-Api-Key)
  samples/sample_batch.json  Datos de ejemplo para probar la ingesta
```

## Puesta en marcha (FatCow — PHP 8.4, MySQL 5.7)

### 1. Base de datos
cPanel → *MySQL Databases*: crea base + usuario. Luego phpMyAdmin → *Importar* → `db/schema.sql`.

### 2. Configuración (credenciales — nunca se suben a git)
```bash
cp web/config/config.sample.php web/config/config.php
```
Rellena `web/config/config.php` con las credenciales MySQL y una `ingest_api_key`
larga y aleatoria:
```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

### 3. Subir por SFTP (puerto 2222)
Sube el **contenido de `web/`** dentro de `public_html/ojoalprecio/`.

### 4. Probar la ingesta (sin correr el scraper)
```powershell
Invoke-RestMethod -Uri "ojoalprecio.online/api/ingest.php" `
  -Method Post -ContentType "application/json" `
  -Headers @{ "X-Api-Key" = "TU_CLAVE" } `
  -InFile "web/samples/sample_batch.json"
```
Espera `inserted: 3` (o `updated: 3` si ya existían).

## Captura diaria con cron-job.org

El runner corre **dentro de FatCow** (las 3 tiendas de la fase 1 son cURL puro).
cron-job.org solo lo dispara por HTTP.

### Probar el runner manualmente
```powershell
Invoke-RestMethod -Uri "ojoalprecio.online/cron/run.php" `
  -Method Get -Headers @{ "X-Api-Key" = "TU_CLAVE" }
```
Espera algo como `{ ok:true, scanned:3, updated:3, fetch_failed:0 }`.
Si `fetch_failed` = total, FatCow bloquea el cURL saliente → pasamos el scraper a
GitHub Actions (plan B).

### Configurar el job en cron-job.org
- **URL:** `ojoalprecio.online/cron/run.php`
- **Método:** GET
- **Headers:** `X-Api-Key: TU_CLAVE`
- **Schedule:** diario (p.ej. 06:00 hora Managua)
- Activar "guardar respuestas" para ver el JSON y recibir aviso si falla.

## Migración al dominio propio
Cambia la URL base `…/ojoalprecio` → `https://tudominio.com` en el cron. Cero
cambios de código.
