<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Script de diagnostico one-off: consulta /competitions en football-data.org
 * y muestra que ligas estan realmente disponibles en el plan actual
 * (el plan gratis restringe a un subconjunto fijo de competiciones).
 *
 * No escribe nada en la base de datos. Solo lee y muestra en pantalla.
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" diagnostico_football_data.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);

if (!$apiKey) {
    echo "No se encontro FOOTBALL_DATA_API_KEY en el .env.\n";
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);

$resp = $http->get(
    'https://api.football-data.org/v4/competitions',
    ["X-Auth-Token: $apiKey"]
);

if ($resp === null) {
    echo "No se pudo conectar a football-data.org.\n";
    exit(1);
}

echo "=== Competiciones disponibles en tu plan ===\n";
foreach ($resp['competitions'] ?? [] as $c) {
    $pais = $c['area']['name'] ?? '?';
    echo "{$c['code']}\t{$c['name']}\t($pais)\n";
}

echo "\nTotal: " . count($resp['competitions'] ?? []) . " competiciones\n";