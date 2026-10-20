<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_horario_crudo.php
//
// Por que existe: poblar_partidos_liga_espn.php asume que el status de
// cada evento vive en competitions[0].status.type.name (mismo shape que
// la version NFL, ya validada en produccion). Pero ese shape para
// deportes 'soccer' de ESPN nunca se probo en vivo desde este entorno
// (sin salida de red a espn.com aca) -- lo dice el propio comentario
// del script. El sintoma que confirma la sospecha: la corrida de
// diagnostico de MLS (13/8) devuelve partidos con fecha de febrero-julio
// (o sea, ya jugados en la realidad) marcados como 'programado' sin
// marcador, cuando deberian venir 'finalizado' con goles.
//
// Este script vuelca el JSON CRUDO de un evento de /schedule para que
// puedas confirmar a mano donde vive el estado real y el marcador antes
// de tocar poblar_partidos_liga_espn.php. Solo lectura, no escribe nada.
//
// Uso:
//   php diagnostico_horario_crudo.php <espn_slug> <espn_team_id> <temporada>
//
// Ejemplo (Nashville SC en MLS, temporada 2026 -- confirmar el espn_team_id
// real con: php diagnostico_espn_crudo.php equipos usa.1):
//   php diagnostico_horario_crudo.php usa.1 <id_de_nashville> 2026

require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

$espnSlug = $argv[1] ?? null;
$teamId = $argv[2] ?? null;
$temporada = $argv[3] ?? null;

if (!$espnSlug || !$teamId || !$temporada) {
    echo "Uso: php diagnostico_horario_crudo.php <espn_slug> <espn_team_id> <temporada>\n";
    echo "Ejemplo: php diagnostico_horario_crudo.php usa.1 <espn_team_id> 2026\n";
    echo "(conseguir el espn_team_id con: php diagnostico_espn_crudo.php equipos usa.1)\n";
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);

$url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/teams/{$teamId}/schedule?season={$temporada}";
$resp = $http->get($url, []);

echo "=== URL: $url ===\n\n";

$eventos = $resp['events'] ?? [];
if (empty($eventos)) {
    echo "No vinieron eventos. JSON completo de la respuesta:\n";
    echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// Mostramos solo el PRIMER evento entero (crudo, sin tocar) para ver el
// shape real. Si necesitas ver mas, subi el limite del array_slice.
echo "Total de eventos en la respuesta: " . count($eventos) . "\n\n";
echo "--- Evento[0] CRUDO completo ---\n";
echo json_encode($eventos[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Ademas, un resumen rapido de las rutas que el script de poblado
// ASUME que existen, evento por evento, para ver de un vistazo si
// vienen vacias/null en la mayoria de los casos.
echo "--- Chequeo rapido de las claves que asume poblar_partidos_liga_espn.php ---\n";
foreach (array_slice($eventos, 0, 8) as $ev) {
    $comp = $ev['competitions'][0] ?? null;
    $statusViaCompetitions = $comp['status']['type']['name'] ?? '(NO existe en competitions[0].status.type.name)';
    $statusViaEventoTop = $ev['status']['type']['name'] ?? '(NO existe en evento.status.type.name)';
    echo sprintf(
        "id=%s | fecha=%s | competitions[0].status.type.name=%s | evento.status.type.name=%s\n",
        $ev['id'] ?? '?',
        $ev['date'] ?? '?',
        $statusViaCompetitions,
        $statusViaEventoTop
    );
}