<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Script de diagnostico one-off: consulta /leagues?id=71 (Brasileirao)
 * en api-sports y muestra la respuesta cruda completa, para confirmar
 * si trae el pais de la liga y bajo que forma exacta (util para el fix
 * de la colision de nombres "Serie A" Italia vs Brasil).
 *
 * No escribe nada en la base de datos. Solo lee y muestra en pantalla.
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" diagnostico_liga_pais.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$apiKey = getenv('API_SPORTS_KEY') ?: ($_ENV['API_SPORTS_KEY'] ?? null);

if (!$apiKey) {
    echo "No se encontro API_SPORTS_KEY en el .env. Revisa el nombre exacto de la variable.\n";
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);

$resp = $http->get(
    'https://v3.football.api-sports.io/leagues?id=71',
    ["x-apisports-key: $apiKey"]
);

echo "=== Respuesta cruda completa ===\n";
echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";