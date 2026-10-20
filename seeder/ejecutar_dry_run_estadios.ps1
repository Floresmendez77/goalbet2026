# ejecutar_dry_run_estadios.ps1
# Corre el dry-run de poblar_estadios_liga_apisports.php para las 5 ligas
# nuevas (Ligue 2, Serie B, Champions League, Brasileirao Serie A, Liga MX)
# y guarda cada salida en su propio .txt dentro de logs\dry_run_estadios\
#
# Uso: parate en la carpeta seeder/ y corre:
#   .\ejecutar_dry_run_estadios.ps1

# Fija el PATH de PHP de MAMP para esta sesion de terminal, por si se abrio
# una terminal nueva y "php" todavia no se reconoce como comando. No pisa
# el PATH existente, solo agrega esta carpeta si no esta ya.
$rutaPhp = "C:\MAMP\bin\php\php8.3.1"
if ($env:Path -notlike "*$rutaPhp*") {
    $env:Path += ";$rutaPhp"
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Host "ERROR: php sigue sin reconocerse aun agregando $rutaPhp al PATH."
    Write-Host "Confirma que la carpeta existe y que el ejecutable se llama php.exe adentro."
    exit 1
}

$carpetaLogs = "logs\dry_run_estadios"
if (-not (Test-Path $carpetaLogs)) {
    New-Item -ItemType Directory -Path $carpetaLogs | Out-Null
}

$fecha = Get-Date -Format "yyyy-MM-dd_HHmm"

# (id_liga_bd, id_liga_api, nombre para el archivo)
$ligas = @(
    @{ bd = 24; api = 62;  nombre = "ligue2" },
    @{ bd = 25; api = 136; nombre = "serieB" },
    @{ bd = 26; api = 2;   nombre = "champions" },
    @{ bd = 27; api = 71;  nombre = "brasileirao" },
    @{ bd = 29; api = 262; nombre = "ligamx" }
)

foreach ($liga in $ligas) {
    $archivo = "$carpetaLogs\$($liga.nombre)_$fecha.txt"
    Write-Host "=== Corriendo dry-run: $($liga.nombre) (id_liga_bd=$($liga.bd), id_liga_api=$($liga.api)) ==="
    php poblar_estadios_liga_apisports.php $liga.bd $liga.api 2023 | Out-File -Encoding utf8 $archivo
    Write-Host "Guardado en: $archivo`n"
}

Write-Host "Listo. Revisa los 5 archivos en $carpetaLogs antes de correr con --aplicar."