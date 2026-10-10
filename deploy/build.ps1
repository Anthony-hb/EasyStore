# =============================================================================
#  EasyStore - Script de despliegue (genera deploy/easystore/)
#
#  Copia SOLO los archivos de produccion del proyecto a deploy/easystore/,
#  dejando fuera los scripts de diagnostico, mantenimiento e instalacion.
#  Puedes ejecutarlo de nuevo cuando quieras regenerar el paquete.
#
#  Uso:  powershell -ExecutionPolicy Bypass -File deploy/build.ps1
# =============================================================================

$ErrorActionPreference = 'Stop'

$root  = Split-Path -Parent $PSScriptRoot          # C:\...\tienda
$dest  = Join-Path $PSScriptRoot 'easystore'       # C:\...\tienda\deploy\easystore

if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
New-Item -ItemType Directory -Path $dest | Out-Null

# --- Carpetas de la aplicacion (copiadas completas) -------------------------
foreach ($d in @('admin', 'api', 'assets', 'auth', 'customer', 'includes')) {
    Copy-Item (Join-Path $root $d) (Join-Path $dest $d) -Recurse
}

# --- uploads: SOLO estructura vacia + proteccion, sin datos locales ---------
$up = Join-Path $dest 'uploads'
New-Item -ItemType Directory -Path $up | Out-Null
Copy-Item (Join-Path $root 'uploads\.htaccess') (Join-Path $up '.htaccess')
foreach ($sub in @('productos', 'comprobantes')) {
    $dir = Join-Path $up $sub
    New-Item -ItemType Directory -Path $dir | Out-Null
    # index.html vacio evita el listado de directorios
    Set-Content -Path (Join-Path $dir 'index.html') -Value '' -Encoding UTF8
}

# --- Archivos raiz que SI van a produccion ----------------------------------
foreach ($f in @('.htaccess', 'index.html', 'admin_setup.php')) {
    Copy-Item (Join-Path $root $f) (Join-Path $dest $f)
}

# --- Configuracion de BD para produccion (reemplaza db.php local) -----------
Copy-Item (Join-Path $PSScriptRoot 'plantillas\db-produccion.php') (Join-Path $dest 'includes\db.php')

# --- Guia de despliegue dentro del paquete ----------------------------------
Copy-Item (Join-Path $PSScriptRoot 'LEEME-DESPLIEGUE.md') (Join-Path $dest 'LEEME-DESPLIEGUE.md')

Write-Host "OK: paquete generado en $dest"
Write-Host "  - Edita includes/db.php con los datos de tu base de datos."
Write-Host "  - Importa deploy\database.sql en phpMyAdmin (base ya creada)."