<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR PAISES + ASIGNAR id_pais A LAS LIGAS QUE QUEDARON EN NULL.
 *
 * DIAGNOSTICO CONFIRMADO (dump de la base, no solo logs): la tabla
 * `paises` solo tenia 4 filas -- Brazil, Argentina, Mexico, USA (las
 * unicas que trajo el flujo de Brasileirao/Liga Profesional/Liga MX/
 * MLS, que usa un `country` con codigo de pais normal). Todas las
 * demas ligas de futbol (Premier League, La Liga, Serie A, Bundesliga,
 * Ligue 1, Ligue 2, Serie B, Champions League) quedaron con
 * `ligas_torneos.id_pais = NULL` desde el dia que se sembraron, y por
 * lo tanto todos sus equipos tambien (ver upsertEquipo() en
 * ApiSportsSeeder.php, que hoy pasa null a mano para id_pais de
 * equipo -- ese es OTRO bug, se corrige aparte, no en este script).
 *
 * POR QUE PASO: ApiSportsSeeder::run() SI tiene el fix que lee el
 * objeto 'country' de /leagues?id=X y llama upsertPais() antes de
 * upsertLiga() (agregado 21/jul, ver comentario en ese archivo) --
 * pero esas 8 ligas se sembraron ANTES de ese fix (13-16/jul) y nunca
 * se volvieron a correr contra /leagues despues. El logo que tienen
 * en ligas_torneos.logo se actualizo despues (3/ago) con otro script
 * puntual que solo toca esa columna, no re-siembra el pais.
 *
 * QUE HACE ESTE SCRIPT:
 *   Por cada liga pendiente (lista fija abajo, mapeada con el
 *   external_id de api-sports-futbol que YA esta guardado en
 *   external_ids -- confirmado contra el dump, no adivinado):
 *     1. Pide /leagues?id=<id_liga_api> a api-sports.io.
 *     2. Lee el objeto 'country' (name, code, flag) de la respuesta.
 *     3. upsertPais() con fuente='api-sports-futbol' y external_id =
 *        country.code si existe, si no country.name como fallback --
 *        MISMA logica que ApiSportsSeeder, a proposito: asi un pais
 *        creado por este script y una corrida futura del seeder
 *        completo apuntan al mismo id, no duplican.
 *     4. UPDATE ligas_torneos SET id_pais = <id resuelto> WHERE id =
 *        <id_liga_bd>.
 *
 * CASO CHAMPIONS LEAGUE (id_liga_bd=26): api-sports.io devuelve
 * country.name = "World" y country.code = null para esta liga (es
 * multi-pais). Con la logica de arriba, este script SI le va a
 * asignar un id_pais -- el de un pseudo-pais "World" que se crea la
 * primera vez (external_id = "World", fallback por code ausente). En
 * la sesion anterior se habia asumido que id_pais=NULL en Champions
 * era el comportamiento esperado/intencional, pero mirando el codigo
 * de ApiSportsSeeder esa NO es la intencion original -- la intencion
 * es resolverlo igual que cualquier liga, usando el nombre como
 * external_id cuando falta el code. Se deja pasar en modo diagnostico
 * como cualquier otra liga: revisar el dry-run y decidir a mano si se
 * quiere ese pseudo-pais "World" o se prefiere excluir Champions de
 * la corrida con --aplicar (pasandola sola, sin el resto).
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_paises.php                          -- procesa TODAS las ligas pendientes (lista fija abajo)
 *   php poblar_paises.php --aplicar                 -- idem, pero escribe de verdad
 *   php poblar_paises.php <id_liga_bd> <id_liga_api> [--aplicar]   -- procesa una sola liga puntual
 *
 * Ejemplos:
 *   php poblar_paises.php
 *   php poblar_paises.php --aplicar
 *   php poblar_paises.php 26 2 --aplicar            -- solo Champions League
 *
 * NOTA: Saudi Pro League (13) y J1 League (14) quedan afuera de la
 * lista fija a proposito -- todavia no tienen NINGUN equipo sembrado
 * ni external_id de api-sports vinculado (ver tabla de estado del
 * proyecto), asi que no hay id_liga_api confirmado para ellas todavia.
 * Cuando se siembren desde cero, el id_pais les va a llegar solo via
 * ApiSportsSeeder (que ya tiene el fix), no hace falta este script.
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_API_SPORTS_FUTBOL = 'api-sports-futbol';
const DOMINIO_API_SPORTS_FUTBOL = 'v3.football.api-sports.io';

// Ligas confirmadas contra external_ids del dump (fuente
// api-sports-futbol) con id_pais actualmente NULL en ligas_torneos.
// id_liga_bd => id_liga_api
const LIGAS_PENDIENTES = [
    15 => 39,   // Premier League
    16 => 140,  // La Liga
    17 => 135,  // Serie A
    21 => 78,   // Bundesliga
    22 => 61,   // Ligue 1
    24 => 62,   // Ligue 2
    25 => 136,  // Serie B
    26 => 2,    // Champions League -- ver nota sobre "World" arriba
];

$aplicar = in_array('--aplicar', $argv, true);

// Argumentos posicionales (ignorando --aplicar) para el modo "una sola liga".
$posicionales = array_values(array_filter(
    array_slice($argv, 1),
    fn($a) => $a !== '--aplicar'
));

if (count($posicionales) >= 2) {
    $idLigaBd = (int)$posicionales[0];
    $idLigaApi = (int)$posicionales[1];
    $ligasAProcesar = [$idLigaBd => $idLigaApi];
} elseif (count($posicionales) === 0) {
    $ligasAProcesar = LIGAS_PENDIENTES;
} else {
    fwrite(STDERR, "Uso: php poblar_paises.php [<id_liga_bd> <id_liga_api>] [--aplicar]\n");
    fwrite(STDERR, "Sin argumentos posicionales, procesa TODAS las ligas de LIGAS_PENDIENTES.\n");
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

$headers = ["x-apisports-key: {$apiKey}"];

$procesadas = 0;
$omitidas = [];

foreach ($ligasAProcesar as $idLigaBd => $idLigaApi) {
    // No pisar una liga que ya tiene id_pais asignado (ej. si el script
    // se corre dos veces, o si alguien ya la resolvio a mano).
    $stmtActual = $pdo->prepare('SELECT nombre, id_pais FROM ligas_torneos WHERE id = ?');
    $stmtActual->execute([$idLigaBd]);
    $ligaActual = $stmtActual->fetch();

    if ($ligaActual === false) {
        $omitidas[] = "id_liga_bd=$idLigaBd (no existe en ligas_torneos)";
        $logger->skipped("liga id_liga_bd=$idLigaBd no existe en ligas_torneos -- se omite");
        continue;
    }

    if ($ligaActual['id_pais'] !== null) {
        $omitidas[] = "{$ligaActual['nombre']} (ya tiene id_pais={$ligaActual['id_pais']})";
        $logger->skipped("liga '{$ligaActual['nombre']}' ya tiene id_pais asignado ({$ligaActual['id_pais']}) -- se omite, no se pisa");
        continue;
    }

    $resp = $http->get(
        "https://" . DOMINIO_API_SPORTS_FUTBOL . "/leagues?id={$idLigaApi}",
        $headers
    );

    if ($resp === null) {
        $logger->error("liga '{$ligaActual['nombre']}' (api-sports id=$idLigaApi): no se pudo obtener /leagues tras reintentos");
        continue;
    }

    $ligaInfo = $resp['response'][0] ?? null;
    if ($ligaInfo === null) {
        $logger->error("liga '{$ligaActual['nombre']}' (api-sports id=$idLigaApi): /leagues no devolvio resultados");
        continue;
    }

    $paisInfo = $ligaInfo['country'] ?? null;
    if ($paisInfo === null || empty($paisInfo['name'])) {
        $logger->skipped("liga '{$ligaActual['nombre']}': la respuesta de api-sports no trajo objeto 'country' -- nada que asignar");
        continue;
    }

    $nombrePais = $paisInfo['name'];
    $codigoPais = $paisInfo['code'] ?? null;
    $banderaPais = $paisInfo['flag'] ?? null;
    $externalIdPais = $codigoPais ?? $nombrePais;

    $procesadas++;

    if (!$aplicar) {
        $logger->info(
            "CREARIA/ACTUALIZARIA pais '$nombrePais'" .
            ($codigoPais ? " (code=$codigoPais)" : " (sin code, external_id sera el nombre)") .
            " -> liga '{$ligaActual['nombre']}' (id=$idLigaBd)"
        );
        continue;
    }

    $idPais = $repo->upsertPais(
        FUENTE_API_SPORTS_FUTBOL,
        $nombrePais,
        $codigoPais,
        $banderaPais,
        $externalIdPais
    );

    if ($idPais === null) {
        continue; // Repository ya logueo el error puntual
    }

    $stmtUpdate = $pdo->prepare('UPDATE ligas_torneos SET id_pais = ? WHERE id = ?');
    $stmtUpdate->execute([$idPais, $idLigaBd]);
    $logger->updated("liga '{$ligaActual['nombre']}' (id=$idLigaBd) -> id_pais=$idPais ('$nombrePais')");
}

echo "\n[RESUMEN] ligas procesadas (con country valido): $procesadas";
if (!empty($omitidas)) {
    echo "\nOmitidas (" . count($omitidas) . "): " . implode(', ', $omitidas);
}
echo "\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien (en particular revisar el caso Champions/\"World\"), correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
    echo "SIGUIENTE PASO: los EQUIPOS de estas ligas todavia tienen id_pais NULL -- eso lo deja\n";
    echo "sin resolver ApiSportsSeeder::run() porque hoy pasa null a mano en upsertEquipo() en vez\n";
    echo "de \$idPais. Ese es un fix aparte en ApiSportsSeeder.php, no en este script.\n";
}

$logger->resumen();