<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_espn_crudo.php
// Muestra la respuesta cruda de ESPN para una liga (lista de equipos) o
// para el roster de un equipo puntual, para verificar el dato antes de
// correr la actualizacion completa. NO modifica nada.
//
// Uso:
//   php diagnostico_espn_crudo.php equipos <slug_liga>
//   php diagnostico_espn_crudo.php roster <slug_liga> <espn_team_id>
//   php diagnostico_espn_crudo.php schedule <slug_liga> <espn_team_id> [temporada]
//
// Ejemplos:
//   php diagnostico_espn_crudo.php equipos arg.1
//   php diagnostico_espn_crudo.php roster esp.1 83   (Barcelona en esp.1, id de ejemplo -- confirmar con el paso 'equipos')
//   php diagnostico_espn_crudo.php schedule arg.1 6756 2026   (Godoy Cruz -- para ver si de verdad no tiene partidos esta temporada bajo arg.1)

require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

$modo = $argv[1] ?? null;
$liga = $argv[2] ?? null;
$teamId = $argv[3] ?? null;

if (!$modo || !$liga) {
    echo "Uso: php diagnostico_espn_crudo.php equipos <slug_liga>\n";
    echo "     php diagnostico_espn_crudo.php roster <slug_liga> <espn_team_id>\n";
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);

if ($modo === 'equipos') {
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$liga}/teams";
} elseif ($modo === 'roster') {
    if (!$teamId) {
        echo "Falta el <espn_team_id>. Corre primero en modo 'equipos' para conseguirlo.\n";
        exit(1);
    }
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$liga}/teams/{$teamId}/roster";
} elseif ($modo === 'schedule') {
    if (!$teamId) {
        echo "Falta el <espn_team_id>. Corre primero en modo 'equipos' para conseguirlo.\n";
        exit(1);
    }
    $temporada = $argv[4] ?? date('Y');
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$liga}/teams/{$teamId}/schedule?season={$temporada}";
} else {
    echo "Modo invalido, usar 'equipos', 'roster' o 'schedule'.\n";
    exit(1);
}

$resp = $http->get($url, []);

echo "=== URL: $url ===\n";
echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";