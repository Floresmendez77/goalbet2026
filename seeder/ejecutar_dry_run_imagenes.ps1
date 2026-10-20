# ejecutar_dry_run_imagenes.ps1
# Corre el dry-run de poblar_imagenes_estadios_wikipedia.php para las
# 5 ligas nuevas y guarda cada log en logs/imagenes_wikipedia_<fecha_hora>/
# No escribe en la base (no pasa --aplicar) -- correr
# ejecutar_aplicar_imagenes.ps1 despues si los 5 logs cierran bien.

$env:PATH = "C:\MAMP\bin\php\php8.3.1\;" + $env:PATH

# Carpeta de logs con timestamp, para no pisar corridas anteriores y
# poder comparar un dry-run viejo contra uno nuevo si hace falta.
$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$carpetaLogs = "logs\imagenes_wikipedia_$timestamp"
New-Item -ItemType Directory -Path $carpetaLogs -Force | Out-Null

$ligas = @{
    24 = "ligue2"
    25 = "serieb"
    26 = "champions"
    27 = "brasileirao"
    29 = "ligamx"
}

foreach ($idLiga in $ligas.Keys) {
    $nombre = $ligas[$idLiga]
    $archivo = Join-Path $carpetaLogs "dryrun_imagenes_$nombre.txt"
    Write-Host "Corriendo liga $idLiga ($nombre)..."
    php poblar_imagenes_estadios_wikipedia.php $idLiga | Out-File -Encoding utf8 $archivo
}

Write-Host "`nListo. Logs guardados en: $carpetaLogs"

# Guardamos la ruta de la carpeta en una variable de sesion para que
# el comando de "juntar todo en un solo txt" (correlo aparte, ver
# instrucciones) sepa donde buscar sin que tengas que escribir el
# timestamp a mano.
$Global:UltimaCarpetaLogsImagenes = $carpetaLogs