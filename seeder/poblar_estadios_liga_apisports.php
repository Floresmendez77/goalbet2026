<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR ESTADIOS DESDE API-SPORTS.IO — llena el hueco que dejo
 * ApiSportsSeeder::run() desde el dia 1: la tabla `estadios` esta
 * vacia para TODO el proyecto (0 filas, ningun equipo de ninguna liga
 * tiene id_estadio) porque ese metodo pasa null,null fijo para
 * id_pais/id_estadio al llamar upsertEquipo() (linea ~171 de
 * ApiSportsSeeder.php) en vez de leer el objeto 'venue' que
 * /teams?league=X&season=Y SI devuelve por equipo (name, address,
 * city, capacity, image). No es un dato que falte en la fuente, es
 * un dato que nunca se leyo.
 *
 * Requiere el FIX aplicado en Repository::upsertEquipo() (ver
 * comentario ahi) -- sin eso, el UPDATE de un equipo ya existente
 * (que es el caso de los 29 de MLS, ya sembrados) ignora id_estadio
 * y este script no serviria de nada la segunda vez que se corre.
 *
 * Por que api-sports.io y no ESPN para esto puntual: el intento de
 * MLS del 21/7 (ver seed_2026-07-21.log) rate-limiteo en /fixtures,
 * NO en /teams -- ese endpoint SI trajo los 29 equipos con exito. O
 * sea que el venue de cada equipo ya estuvo disponible una vez, solo
 * que se descarto. Pedirlo nuevamente es la ruta mas corta (mismos
 * 29 equipos, mismo external_id de api-sports-futbol, no hay que
 * matchear nada por nombre -- se busca el vinculo exacto en
 * external_ids, igual que el resto del proyecto).
 *
 * Como lo resuelve:
 *   1. Pide /teams?league=<id_liga_api>&season=<temporada> (mismo
 *      endpoint y misma temporada que uso ApiSportsSeeder para crear
 *      los equipos -- necesitas la MISMA temporada para que el
 *      external_id de cada equipo matchee el que ya esta en tu base).
 *   2. Por cada equipo de la respuesta, resuelve el id interno via
 *      external_ids (fuente='api-sports-futbol', entidad='equipo').
 *      Si no hay vinculo, se omite y se loguea -- este script nunca
 *      crea un equipo nuevo, solo completa datos de uno que ya existe.
 *   3. Si el equipo trae 'venue' con nombre, upsertEstadio() (crea o
 *      actualiza el estadio) y despues upsertEquipo() con el mismo
 *      external_id de siempre para linkear id_estadio (y de paso
 *      id_pais, mismo bug/mismo fix).
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_estadios_liga_apisports.php <id_liga_bd> <id_liga_api> <temporada> [--aplicar]
 *
 * Ejemplo (MLS: id_liga_bd=30 en tu base, id_liga_api=253 en
 * api-sports.io -- el mismo 253 que ya esta en external_ids, ver
 * seed_log id 22170 / ligas_torneos fila 30):
 *   php poblar_estadios_liga_apisports.php 30 253 2023
 *   php poblar_estadios_liga_apisports.php 30 253 2023 --aplicar
 *
 * OJO: la temporada es 2023 a proposito, no 2026 -- es la misma
 * temporada con la que se sembraron los equipos originalmente (ver
 * ligas_torneos.temporada='2023' para la fila 30, y la nota en
 * ApiSportsSeeder.php sobre que el plan gratis de api-sports.io solo
 * acepta 2022-2024 para /teams). Pedir 2026 aca solo traeria una
 * lista de equipos distinta que NO matchea los external_id que ya
 * tenes guardados, y todo se omitiria como "sin vinculo". El
 * calendario real 2026-27 lo trae poblar_partidos_liga_espn.php
 * (otra fuente, no tiene esta restriccion de temporada).
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_API_SPORTS_FUTBOL = 'api-sports-futbol';
const DOMINIO_API_SPORTS_FUTBOL = 'v3.football.api-sports.io';

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$idLigaApi = isset($argv[2]) ? (int)$argv[2] : null;
$temporada = $argv[3] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$idLigaBd || !$idLigaApi || !$temporada) {
    fwrite(STDERR, "Uso: php poblar_estadios_liga_apisports.php <id_liga_bd> <id_liga_api> <temporada> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php poblar_estadios_liga_apisports.php 30 253 2023 --aplicar\n");
    exit(1);
}

$apiKey = Env::get('API_SPORTS_KEY', '');
if ($apiKey === '') {
    fwrite(STDERR, "Falta API_SPORTS_KEY en el .env. Revisa el nombre exacto de la variable.\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

// id_pais de la liga, para asociarlo tambien a los equipos/estadios
// que hoy viven con id_pais NULL (mismo bug que id_estadio).
$stmtPais = $pdo->prepare('SELECT id_pais FROM ligas_torneos WHERE id = ?');
$stmtPais->execute([$idLigaBd]);
$idPaisLiga = $stmtPais->fetchColumn();
$idPaisLiga = $idPaisLiga !== false && $idPaisLiga !== null ? (int)$idPaisLiga : null;

$idDeporte = $repo->idDeportePorSlug('futbol');

$headers = ["x-apisports-key: {$apiKey}"];
$resp = $http->get(
    "https://" . DOMINIO_API_SPORTS_FUTBOL . "/teams?league={$idLigaApi}&season={$temporada}",
    $headers
);

if ($resp === null) {
    echo "No se pudo obtener /teams desde api-sports.io tras los reintentos (probable rate limit de nuevo).\n";
    echo "Esto NO escribio nada. Probar de nuevo mas tarde (el limite del plan gratis suele resetear por dia).\n";
    exit(1);
}

if (isset($resp['errors']['plan'])) {
    echo "api-sports.io respondio un error de plan: {$resp['errors']['plan']}\n";
    echo "Revisa que <temporada> sea una de las que acepta el plan gratis (2022-2024 para futbol, confirmado en ApiSportsSeeder.php).\n";
    exit(1);
}

$equipos = $resp['response'] ?? [];
if (empty($equipos)) {
    echo "La respuesta de api-sports.io no trajo equipos para liga=$idLigaApi temporada=$temporada. Nada que hacer.\n";
    exit(0);
}
echo count($equipos) . " equipos recibidos de api-sports.io para liga=$idLigaApi temporada=$temporada.\n\n";

$sinVinculo = [];
$sinVenue = [];
$procesados = 0;

foreach ($equipos as $eq) {
    $datosEquipo = $eq['team'] ?? null;
    $venue = $eq['venue'] ?? null;

    if ($datosEquipo === null || empty($datosEquipo['id'])) {
        continue;
    }

    $idExterno = (string)$datosEquipo['id'];
    $nombreEquipo = $datosEquipo['name'] ?? "equipo $idExterno";

    // No matchea por nombre: este equipo YA deberia existir con este
    // external_id exacto (lo creo ApiSportsSeeder). Si no hay vinculo,
    // algo cambio (otra temporada, otra liga) -- se omite, no se crea.
    $idEquipoDb = $repo->resolverExternalId(FUENTE_API_SPORTS_FUTBOL, 'equipo', $idExterno);
    if ($idEquipoDb === null) {
        $sinVinculo[] = $nombreEquipo;
        $logger->skipped("equipo '$nombreEquipo' (api-sports-futbol:$idExterno) sin vinculo previo en external_ids -- se omite");
        continue;
    }

    // SALVAGUARDA: un mismo equipo (mismo external_id de api-sports) puede
    // aparecer en mas de una competencia -- el caso tipico es Champions
    // League, donde vienen Real Madrid, Bayern, Man United, etc. que YA
    // tienen su id_liga domestica real asignada (16, 21, 15...). Este
    // script es solo para completar estadio/pais, NUNCA debe pisar la
    // liga principal de un equipo que ya la tiene asignada a otra cosa.
    // Si detecta esto, preserva la liga actual y solo toca estadio/pais.
    $idLigaParaEsteEquipo = $idLigaBd;
    $idPaisParaEsteEquipo = $idPaisLiga;
    $stmtActual = $pdo->prepare('SELECT id_liga, id_pais FROM equipos WHERE id = ?');
    $stmtActual->execute([$idEquipoDb]);
    $actual = $stmtActual->fetch();
    if ($actual) {
        if ($actual['id_liga'] !== null && (int)$actual['id_liga'] !== $idLigaBd) {
            $idLigaParaEsteEquipo = (int)$actual['id_liga'];
            $logger->info("equipo '$nombreEquipo' ya pertenece a otra liga (id_liga={$actual['id_liga']}) -- se preserva, NO se reasigna a $idLigaBd");
        }
        // No pisar un id_pais ya asignado con NULL (pasa con Champions,
        // que es multi-pais y tiene id_pais=NULL en ligas_torneos).
        if ($actual['id_pais'] !== null && $idPaisLiga === null) {
            $idPaisParaEsteEquipo = (int)$actual['id_pais'];
        }
    }

    $nombreEstadio = $venue['name'] ?? null;
    if ($venue === null || empty($nombreEstadio)) {
        $sinVenue[] = $nombreEquipo;
        $logger->skipped("equipo '$nombreEquipo': api-sports.io no trajo venue/estadio en esta respuesta");
        continue;
    }

    $ciudad = $venue['city'] ?? null;
    $capacidad = isset($venue['capacity']) ? (int)$venue['capacity'] : null;
    $imagen = $venue['image'] ?? null;
    // No todos los venues de api-sports traen 'id' propio -- si falta,
    // se arma un external_id propio por equipo para no colisionar con
    // el estadio de otro equipo que tampoco tenga id.
    $externalIdEstadio = isset($venue['id']) && $venue['id'] !== null
        ? (string)$venue['id']
        : "team-{$idExterno}";

    $procesados++;

    if (!$aplicar) {
        $logger->info("CREARIA/ACTUALIZARIA estadio '$nombreEstadio' ($ciudad, cap. " . ($capacidad ?? '?') . ") -> equipo '$nombreEquipo'");
        continue;
    }

    $idEstadio = $repo->upsertEstadio(
        FUENTE_API_SPORTS_FUTBOL,
        $nombreEstadio,
        $ciudad,
        $idPaisLiga,
        $capacidad,
        $imagen,
        $externalIdEstadio
    );
    if ($idEstadio === null) {
        continue; // Repository ya logueo el error puntual
    }

    // Mismo external_id de siempre -> upsertGenerico toma el camino de
    // UPDATE del equipo ya existente (requiere el FIX en Repository.php
    // que agrega id_estadio/id_pais al $update, ver ese archivo).
    $repo->upsertEquipo(
        FUENTE_API_SPORTS_FUTBOL,
        $idDeporte,
        $idLigaParaEsteEquipo,
        $idPaisParaEsteEquipo,
        $idEstadio,
        $nombreEquipo,
        null,
        null,
        $datosEquipo['logo'] ?? null,
        null,
        null,
        $idExterno
    );
}

echo "\n[RESUMEN] equipos procesados: $procesados";
if (!empty($sinVinculo)) {
    echo "\nSin vinculo previo en external_ids (" . count($sinVinculo) . "): " . implode(', ', $sinVinculo);
}
if (!empty($sinVenue)) {
    echo "\nSin datos de venue en la respuesta (" . count($sinVenue) . "): " . implode(', ', $sinVenue);
}
echo "\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}

$logger->resumen();