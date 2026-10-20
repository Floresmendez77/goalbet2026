<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_squad_crudo.php
// Muestra la respuesta CRUDA de football-data para un equipo puntual,
// para diagnosticar por que actualizar_plantilla.php no pudo traer su
// plantilla (rate limit, plan restringido, estructura distinta, etc).
// NO modifica nada.
//
// Correr: & "C:\MAMP\bin\php\php8.3.1\php.exe" diagnostico_squad_crudo.php <id_football_data>
// Ejemplo (Valencia): diagnostico_squad_crudo.php 95

require __DIR__ . '/Env.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$idFD = $argv[1] ?? null;
if (!$idFD) {
    echo "Uso: php diagnostico_squad_crudo.php <id_football_data>\n";
    exit(1);
}

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);

$logger = new Logger();
$http = new HttpClient($logger);

$resp = $http->get(
    "https://api.football-data.org/v4/teams/{$idFD}",
    ["X-Auth-Token: $apiKey"]
);

echo "=== Respuesta cruda completa ===\n";
echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";