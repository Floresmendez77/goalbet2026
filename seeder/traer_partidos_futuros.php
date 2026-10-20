<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Trae partidos futuros de football-data para una liga, reutilizando
 * los equipos ya vinculados por el puente (puente_equipos_aplicar.php)
 * en vez de recrearlos.
 *
 * Requisito: correr el puente ANTES para esta liga, si no todos los
 * equipos se van a crear como nuevos (duplicados de los historicos).
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" traer_partidos_futuros.php BSA 9
 *
 * Donde:
 *   BSA = codigo de la competicion en football-data.org
 *   9   = id interno (ligas_torneos.id) de la liga DESTINO (la version
 *         "futuro/2026" que ya existe en tu base, no la historica)
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Seeders/FootballDataSeeder.php';

Env::load(__DIR__ . '/.env');

$codigoLiga = $argv[1] ?? null;
$idLigaDestino = isset($argv[2]) ? (int)$argv[2] : null;

if (!$codigoLiga || !$idLigaDestino) {
    echo "Uso: php traer_partidos_futuros.php <CODIGO_FOOTBALL_DATA> <ID_LIGA_DESTINO>\n";
    echo "Ejemplo: php traer_partidos_futuros.php BSA 9\n";
    exit(1);
}

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);
if (!$apiKey) {
    echo "No se encontro FOOTBALL_DATA_API_KEY en el .env.\n";
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

$seeder = new FootballDataSeeder($repo, $http, $logger, $apiKey);

echo "Trayendo partidos de $codigoLiga hacia liga interna $idLigaDestino...\n";
$seeder->runSoloPartidos($codigoLiga, $idLigaDestino);
echo "Listo. Revisa el log para el resumen (reutilizados vs nuevos).\n";