# ejecutar_aplicar_estadios.ps1
# Corre poblar_estadios_liga_apisports.php CON --aplicar (esto SI escribe
# en la base) para las 5 ligas nuevas, y guarda cada salida en su propio
# .txt dentro de logs\aplicar_estadios\
#
# Uso: parate en la carpeta seeder/ y corre:
#   .\ejecutar_aplicar_estadios.ps1

$rutaPhp = "C:\MAMP\bin\php\php8.3.1"
if ($env:Path -notlike "*$rutaPhp*") {
    $env:Path += ";$rutaPhp"
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Host "ERROR: php sigue sin reconocerse aun agregando $rutaPhp al PATH."
    Write-Host "Confirma que la carpeta existe y que el ejecutable se llama php.exe adentro."
    exit 1
}

$carpetaLogs = "logs\aplicar_estadios"
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

Write-Host "=== ATENCION: esto va a ESCRIBIR en la base de datos (--aplicar) ===`n"

foreach ($liga in $ligas) {
    $archivo = "$carpetaLogs\$($liga.nombre)_$fecha.txt"
    Write-Host "=== Aplicando: $($liga.nombre) (id_liga_bd=$($liga.bd), id_liga_api=$($liga.api)) ==="
    php poblar_estadios_liga_apisports.php $liga.bd $liga.api 2023 --aplicar | Out-File -Encoding utf8 $archivo
    Write-Host "Guardado en: $archivo`n"
}

Write-Host "Listo. Revisa los 5 archivos en $carpetaLogs para confirmar 'creados/actualizados' y 0 errores."