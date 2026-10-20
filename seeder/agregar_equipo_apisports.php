<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * AGREGAR UN EQUIPO PUNTUAL A UNA LIGA YA SEMBRADA — via api-sports.io.
 *
 * Por que existe: MLS (id_liga=30) se sembro originalmente con
 * temporada=2023 en api-sports (asi lo documenta el plan de esta
 * sesion, a proposito, para que el external_id matchee con el que se
 * uso para /fixtures y /venues). El problema es que San Diego FC es
 * un equipo de EXPANSION que se sumo a la MLS recien en 2025 -- no
 * jugaba en 2023, asi que /teams?league=253&season=2023 nunca lo
 * devuelve. Confirmado con poblar_partidos_liga_espn.php: los 24
 * partidos que ESPN marca contra 'San Diego FC' se omiten porque el
 * equipo directamente NO EXISTE en tu tabla `equipos` (a diferencia de
 * LA Galaxy/LAFC, que si existian pero con nombre que no matcheaba).
 *
 * Por que NO se reutiliza ApiSportsSeeder::run() (el que usa
 * run_seeder.php --fuente=api-sports) para esto:
 *   1) ApiSportsSeeder::run() hace tambien upsertLiga() con la
 *      $temporada que le pases -- si corres con season=2025 o 2026
 *      para agarrar a San Diego, de paso PISA el campo `temporada` de
 *      `ligas_torneos` para id_liga=30, que ya esta seteado a la
 *      2026-27 correcta (ver ESTADO ACTUAL en el contexto del
 *      proyecto). No vale la pena el riesgo por un solo equipo.
 *   2) run_seeder.php no tiene modo diagnostico/dry-run -- escribe
 *      directo. Rompe la regla del proyecto de dry-run por default.
 * Este script SOLO llama /teams (sin tocar /leagues), filtra por
 * nombre, y llama Repository::upsertEquipo() directo para ESE equipo
 * puntual. Todo lo demas de la liga queda intacto.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php agregar_equipo_apisports.php <id_liga_bd> <id_liga_api> <temporada> <busqueda_nombre> <id_pais> [--aplicar]
 *
 * Ejemplo (San Diego FC en MLS -- confirmar antes con --aplicar que
 * temporada de api-sports SI lo trae; probar 2025 primero):
 *   php agregar_equipo_apisports.php 30 253 2025 "San Diego" 4
 *   php agregar_equipo_apisports.php 30 253 2025 "San Diego" 4 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_APISPORTS_FUTBOL = 'api-sports-futbol';

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$idLigaApi = isset($argv[2]) ? (int)$argv[2] : null;
$temporada = $argv[3] ?? null;
$busquedaNombre = $argv[4] ?? null;
$idPais = isset($argv[5]) ? (int)$argv[5] : null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$idLigaBd || !$idLigaApi || !$temporada || !$busquedaNombre) {
    fwrite(STDERR, "Uso: php agregar_equipo_apisports.php <id_liga_bd> <id_liga_api> <temporada> <busqueda_nombre> <id_pais> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php agregar_equipo_apisports.php 30 253 2025 \"San Diego\" 4\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para escribir de verdad ===\n\n";

$apiKey = Env::get('API_SPORTS_KEY', '');
$resp = $http->get(
    "https://v3.football.api-sports.io/teams?league={$idLigaApi}&season={$temporada}",
    ["x-apisports-key: {$apiKey}"]
);

if (isset($resp['errors']['plan'])) {
    echo "ERROR de api-sports: " . $resp['errors']['plan'] . "\n";
    exit(1);
}

$equipos = $resp['response'] ?? [];
echo "api-sports devolvio " . count($equipos) . " equipo(s) para liga={$idLigaApi} temporada={$temporada}.\n\n";

$candidatos = array_values(array_filter($equipos, function ($eq) use ($busquedaNombre) {
    $nombre = $eq['team']['name'] ?? '';
    return stripos($nombre, $busquedaNombre) !== false;
}));

if (empty($candidatos)) {
    echo "No se encontro ningun equipo que contenga '$busquedaNombre' en el nombre, para esta temporada.\n";
    echo "Si el equipo es de expansion reciente, proba con otra temporada (ej. 2025 o 2026) -- \n";
    echo "puede que en 2023 (la que uso el resto de MLS) todavia no existiera en api-sports.\n";
    exit(0);
}

foreach ($candidatos as $eq) {
    $idExterno = (string)$eq['team']['id'];
    $nombre = $eq['team']['name'];
    $logo = $eq['team']['logo'] ?? null;
    $fundacion = $eq['team']['founded'] ?? null;

    $yaExiste = $repo->resolverExternalId(FUENTE_APISPORTS_FUTBOL, 'equipo', $idExterno);

    echo "Candidato: '$nombre' (api-sports id=$idExterno)" .
        ($yaExiste !== null ? " -- YA EXISTE en tu base como equipo #$yaExiste (esto seria un UPDATE, no un alta nueva)\n" : " -- NO existe todavia en tu base (esto CREARIA un equipo nuevo)\n");

    if (!$aplicar) {
        continue;
    }

    $idResultante = $repo->upsertEquipo(
        FUENTE_APISPORTS_FUTBOL,
        $repo->idDeportePorSlug('futbol'),
        $idLigaBd,
        $idPais,
        null, // id_estadio -- se completa despues con poblar_estadios_liga_apisports.php
        $nombre,
        null,
        null,
        $logo,
        null,
        null,
        $idExterno
    );

    echo $idResultante !== null
        ? "-> Guardado como equipo #$idResultante.\n"
        : "-> Fallo el upsert, revisar seeder/logs/.\n";
}

echo "\n";
if (!$aplicar) {
    echo "Esto fue solo un diagnostico. Si el candidato es el correcto, correr de nuevo con --aplicar.\n";
}