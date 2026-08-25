# ============================================================================
#  Crawl LOCAL — tiendas que bloquean IPs de datacenter (telcmax) y solo se
#  pueden bajar desde una IP RESIDENCIAL (tu casa). Pensado para correr al
#  encender la PC / iniciar sesion (Task Scheduler, ver instrucciones abajo).
#
#  Requiere DOS variables de entorno (configuralas UNA sola vez, ver README):
#     OJO_INGEST_URL   -> el endpoint de ingesta (el mismo que ya usas)
#     OJO_INGEST_KEY   -> la clave de ingesta (NUNCA la pongas en este archivo)
#
#  El script las lee del entorno; si no estan, aborta y lo anota en el log.
# ============================================================================

$ErrorActionPreference = 'Continue'
$repo = 'C:\Users\fmxso\KEEPANI'
$log  = Join-Path $env:TEMP 'ojo_crawl_local.log'

# Tiendas residential_only a bajar (agrega mas si aparecen).
$stores = @('telcmax')

function Log($m) { "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $m" | Add-Content -Encoding utf8 $log }

# --- 1) Verificar credenciales (sin exponerlas) ---
if ([string]::IsNullOrWhiteSpace($env:OJO_INGEST_URL) -or [string]::IsNullOrWhiteSpace($env:OJO_INGEST_KEY)) {
    Log "ABORTA: faltan OJO_INGEST_URL / OJO_INGEST_KEY en el entorno."
    exit 1
}

# --- 2) Esperar a que haya red (hasta ~2 min tras encender la PC) ---
for ($i = 0; $i -lt 24; $i++) {
    if (Test-Connection -Quiet -Count 1 -ComputerName 8.8.8.8) { break }
    Start-Sleep -Seconds 5
}

# --- 3) Crawl de cada tienda ---
Set-Location $repo
foreach ($s in $stores) {
    Log "crawl '$s' ..."
    try {
        $out = & php web/cli/crawl_woocommerce.php $s 2>&1 | Out-String
        Log $out.Trim()
    } catch {
        Log "ERROR en '$s': $($_.Exception.Message)"
    }
}
Log "listo."
