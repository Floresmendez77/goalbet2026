<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * AGREGAR ESTADIO DE SAN DIEGO FC -- uno-off, no un script generico de
 * liga. San Diego FC (id 523 en tu base) quedo sin id_estadio porque
 * se creo via ESPN (expansion MLS 2025, fuera del rango 2022-2024 que
 * cubre el plan gratis de api-sports) y por lo tanto nunca aparecio en
 * la respuesta de poblar_estadios_liga_apisports.php -- no es un bug,
 * es la limitacion de plan ya documentada.
 *
 * Datos confirmados a mano (Wikipedia / MLS oficial, agosto 2026):
 * San Diego FC juega en Snapdragon Stadium, San Diego, California,
 * capacidad 35,000 -- estadio que comparte con San Diego Wave FC
 * (NWSL) y los San Diego State Aztecs (NCAA), no es exclusivo del
 * club, pero es su sede real.
 *
 * Como lo resuelve:
 *   1. Crea (o reutiliza si ya existe) el estadio via
 *      Repository::upsertEstadio(), con fuente='manual-sandiego' y
 *      external_id='sdfc-snapdragon' (idempotente -- correrlo dos
 *      veces no duplica el estadio).
 *   2. Busca la foto en Wikipedia con la misma logica de
 *      poblar_imagenes_estadios_wikipedia.php (busqueda + resumen
 *      REST), asi no queda una URL vieja/rota pegada a mano.
 *   3. Vincula el estadio al equipo 523 via
 *      Repository::upsertEquipo() con el MISMO external_id de siempre
 *      para San Diego FC en fuente 'espn-futbol' (espnId=22529, ver
 *      nota de la sesion anterior) -- upsertGenerico toma el camino
 *      de UPDATE del equipo ya existente, requiere el FIX de
 *      id_estadio/id_pais en Repository::upsertEquipo() (ya aplicado).
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php agregar_estadio_sandiego.php [--aplicar]
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const ID_EQUIPO_SAN_DIEGO = 523;
const ESPN_ID_SAN_DIEGO = '22529'; // confirmado sesion anterior (agregar_equipo_espn.php)
const FUENTE_ESPN_FUTBOL = 'espn-futbol';
const FUENTE_ESTADIO_MANUAL = 'manual-sandiego';
const EXTERNAL_ID_ESTADIO = 'sdfc-snapdragon';

$aplicar = in_array('--aplicar', $argv, true);

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

// Confirmar que el equipo 523 existe y es San Diego FC antes de tocar nada.
$stmt = $pdo->prepare('SELECT id, nombre, id_deporte, id_liga, id_pais, id_estadio FROM equipos WHERE id = ?');
$stmt->execute([ID_EQUIPO_SAN_DIEGO]);
$equipo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($equipo === false) {
    fwrite(STDERR, "No se encontro el equipo id=" . ID_EQUIPO_SAN_DIEGO . ". Aborta.\n");
    exit(1);
}
if (stripos($equipo['nombre'], 'San Diego') === false) {
    fwrite(STDERR, "El equipo id=" . ID_EQUIPO_SAN_DIEGO . " se llama '{$equipo['nombre']}', no 'San Diego FC'. Aborta por seguridad.\n");
    exit(1);
}

echo "Equipo confirmado: '{$equipo['nombre']}' (id=" . ID_EQUIPO_SAN_DIEGO . "), id_estadio actual: " . ($equipo['id_estadio'] ?? 'NULL') . "\n\n";

// Buscar foto real en Wikipedia (misma logica que el script de imagenes).
$nombreEstadio = 'Snapdragon Stadium';
$ciudad = 'San Diego, California';
$capacidad = 35000;

$titulo = buscarTituloWikipedia($http, $nombreEstadio, 'San Diego', 'en');
$urlImagen = null;
if ($titulo !== null) {
    $resumen = obtenerResumenWikipedia($http, $titulo, 'en');
    $urlImagen = $resumen['originalimage']['source'] ?? $resumen['thumbnail']['source'] ?? null;
    if ($urlImagen !== null) {
        $urlImagen = strtok($urlImagen, '?');
    }
}

echo "Pagina Wikipedia encontrada: " . ($titulo ?? 'NINGUNA') . "\n";
echo "URL de imagen: " . ($urlImagen ?? 'NINGUNA -- se guardara NULL') . "\n\n";

if (!$aplicar) {
    echo "CREARIA/ACTUALIZARIA estadio '$nombreEstadio' ($ciudad, cap. $capacidad) -> equipo '{$equipo['nombre']}'\n";
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
    $logger->resumen();
    exit(0);
}

$idEstadio = $repo->upsertEstadio(
    FUENTE_ESTADIO_MANUAL,
    $nombreEstadio,
    $ciudad,
    $equipo['id_pais'],
    $capacidad,
    $urlImagen,
    EXTERNAL_ID_ESTADIO
);

if ($idEstadio === null) {
    fwrite(STDERR, "Fallo upsertEstadio, revisar log. Aborta sin tocar equipos.\n");
    exit(1);
}

$repo->upsertEquipo(
    FUENTE_ESPN_FUTBOL,
    (int)$equipo['id_deporte'],
    $equipo['id_liga'] !== null ? (int)$equipo['id_liga'] : null,
    $equipo['id_pais'] !== null ? (int)$equipo['id_pais'] : null,
    $idEstadio,
    $equipo['nombre'],
    null,
    null,
    null, // no tocar escudo aca
    null,
    null,
    ESPN_ID_SAN_DIEGO
);

echo "\nListo. San Diego FC vinculado a estadio id=$idEstadio ('$nombreEstadio').\n";
$logger->resumen();

/**
 * Copiadas de poblar_imagenes_estadios_wikipedia.php para no crear
 * una dependencia cruzada entre scripts uno-off -- si esta logica
 * cambia alla, no hace falta que este script la siga (es de un solo
 * uso).
 */
function buscarTituloWikipedia(HttpClient $http, string $nombreEstadio, ?string $ciudad, string $idioma): ?string
{
    $intentos = [$nombreEstadio];
    if ($ciudad) {
        $intentos[] = "$nombreEstadio $ciudad";
    }
    foreach ($intentos as $consulta) {
        $url = "https://$idioma.wikipedia.org/w/api.php?action=query&list=search"
            . "&srsearch=" . urlencode($consulta)
            . "&srlimit=1&format=json";
        $resp = $http->get($url, ['User-Agent: GoalBet2026-Seeder/1.0 (proyecto educativo CEUTEC)']);
        if ($resp === null) {
            continue;
        }
        $resultados = $resp['query']['search'] ?? [];
        if (!empty($resultados)) {
            return $resultados[0]['title'];
        }
    }
    return null;
}

function obtenerResumenWikipedia(HttpClient $http, string $titulo, string $idioma): array
{
    $tituloUrl = rawurlencode(str_replace(' ', '_', $titulo));
    $url = "https://$idioma.wikipedia.org/api/rest_v1/page/summary/$tituloUrl";
    $resp = $http->get($url, ['User-Agent: GoalBet2026-Seeder/1.0 (proyecto educativo CEUTEC)']);
    return $resp ?? [];
}