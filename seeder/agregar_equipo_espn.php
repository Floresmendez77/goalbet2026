<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * AGREGAR UN EQUIPO PUNTUAL A UNA LIGA YA SEMBRADA — via ESPN (en vez
 * de api-sports).
 *
 * Por que existe: se intento agregar San Diego FC (equipo de expansion
 * MLS 2025, faltante en tu base de 30 equipos) con
 * agregar_equipo_apisports.php, pero el plan gratuito de api-sports
 * devuelve: "Free plans do not have access to this season, try from
 * 2022 to 2024". San Diego FC no jugo en ninguna de esas temporadas
 * (recien debuto en 2025), asi que api-sports queda descartado para
 * este equipo puntual con el plan actual.
 *
 * Alternativa: la misma API publica de ESPN que ya usa
 * poblar_partidos_liga_espn.php (site.api.espn.com/.../usa.1/teams) SI
 * trae a San Diego FC en el listado actual de equipos -- ya lo vimos
 * aparecer como rival con espnId=22529 en los dry-runs de partidos.
 * Este script busca por nombre en ese mismo listado y crea el equipo
 * con fuente='espn-futbol' (el mismo namespace que ya usa
 * poblar_partidos_liga_espn.php para partidos), asi que apenas se cree
 * el equipo, el matching por nombre de ese script lo va a encontrar
 * solo, sin necesitar vincular_equipo_espn.php.
 *
 * LIMITACION CONOCIDA: como este equipo NO se puede traer de
 * api-sports con el plan actual, poblar_estadios_liga_apisports.php
 * (que lee el venue desde api-sports) no le va a poder completar el
 * estadio a San Diego FC -- va a quedar con id_estadio NULL hasta que
 * se resuelva a mano o se actualice el plan de api-sports.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php agregar_equipo_espn.php <id_liga_bd> <espn_slug> <busqueda_nombre> <id_pais> [--aplicar]
 *
 * Ejemplo (San Diego FC en MLS):
 *   php agregar_equipo_espn.php 30 usa.1 "San Diego" 4
 *   php agregar_equipo_espn.php 30 usa.1 "San Diego" 4 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_ESPN_FUTBOL_AGREGAR = 'espn-futbol';

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$espnSlug = $argv[2] ?? null;
$busquedaNombre = $argv[3] ?? null;
$idPais = isset($argv[4]) ? (int)$argv[4] : null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$idLigaBd || !$espnSlug || !$busquedaNombre) {
    fwrite(STDERR, "Uso: php agregar_equipo_espn.php <id_liga_bd> <espn_slug> <busqueda_nombre> <id_pais> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php agregar_equipo_espn.php 30 usa.1 \"San Diego\" 4\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para escribir de verdad ===\n\n";

$resp = $http->get("https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/teams?limit=100", []);
$equipos = $resp['sports'][0]['leagues'][0]['teams'] ?? [];

echo "ESPN devolvio " . count($equipos) . " equipo(s) para '$espnSlug'.\n\n";

$candidatos = array_values(array_filter($equipos, function ($item) use ($busquedaNombre) {
    $nombre = $item['team']['displayName'] ?? $item['team']['name'] ?? '';
    return stripos($nombre, $busquedaNombre) !== false;
}));

if (empty($candidatos)) {
    echo "No se encontro ningun equipo que contenga '$busquedaNombre' en el nombre.\n";
    exit(0);
}

foreach ($candidatos as $item) {
    $eq = $item['team'];
    $idExterno = (string)$eq['id'];
    $nombre = $eq['displayName'] ?? $eq['name'];
    $logo = $eq['logos'][0]['href'] ?? null;

    $yaExiste = $repo->resolverExternalId(FUENTE_ESPN_FUTBOL_AGREGAR, 'equipo', $idExterno);

    echo "Candidato: '$nombre' (espnId=$idExterno)" .
        ($yaExiste !== null ? " -- YA EXISTE en tu base como equipo #$yaExiste (esto seria un UPDATE, no un alta nueva)\n" : " -- NO existe todavia en tu base (esto CREARIA un equipo nuevo)\n");

    if (!$aplicar) {
        continue;
    }

    $idResultante = $repo->upsertEquipo(
        FUENTE_ESPN_FUTBOL_AGREGAR,
        $repo->idDeportePorSlug('futbol'),
        $idLigaBd,
        $idPais,
        null, // id_estadio -- api-sports no tiene este equipo con el plan actual, queda pendiente
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
} else {
    echo "Listo. Recorda correr de nuevo poblar_partidos_liga_espn.php y actualizar_plantilla_espn.php\n";
    echo "para que este equipo nuevo reciba sus partidos y jugadores.\n";
}