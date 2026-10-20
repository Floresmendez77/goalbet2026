<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * AGREGAR ESTADIOS MANUALES -- 5 EQUIPOS ARGENTINOS AGREGADOS VIA ESPN
 * ----------------------------------------------------------------------
 * Mismo caso que San Diego FC en MLS (ver agregar_estadio_sandiego.php):
 * estos 5 equipos se agregaron a la liga 28 via agregar_equipo_espn.php
 * (expansion/incorporaciones recientes al torneo, fuera del rango
 * 2022-2024 que cubre el plan gratis de api-sports), asi que nunca
 * aparecieron en la respuesta de poblar_estadios_liga_apisports.php --
 * no es un bug, es la limitacion de plan ya documentada.
 *
 * Datos confirmados a mano (Wikipedia ES/EN + Transfermarkt/Soccerway,
 * coincidentes, agosto 2026):
 *   - Aldosivi                    -> Estadio Jose Maria Minella (Mar del Plata), cap. 35180
 *   - Independiente Rivadavia     -> Estadio Bautista Gargantini (Mendoza), cap. 24000
 *   - Deportivo Riestra           -> Estadio Guillermo Laza (CABA), cap. 3000
 *   - Estudiantes de Rio Cuarto   -> Estadio Ciudad de Rio Cuarto - Antonio Candini (Cordoba), cap. 12000
 *   - Gimnasia (Mendoza)          -> Estadio Victor Antonio Legrotaglie (Mendoza), cap. 14000
 *
 * A diferencia de agregar_estadio_sandiego.php (que hardcodea un solo
 * id_equipo ya confirmado), aca el id_equipo de 2 de los 5 no estaba
 * confirmado en el contexto de la sesion anterior -- por seguridad,
 * cada equipo se busca por un FRAGMENTO DE NOMBRE UNICO dentro de
 * id_liga=28 (regla del proyecto: fragmento lo mas especifico posible
 * para no matchear de rebote otro equipo ya existente). Si un
 * fragmento no da EXACTAMENTE 1 resultado, ese equipo puntual se
 * saltea con un warning -- no aborta el script entero, para no
 * bloquear a los otros 4 por un problema de uno solo.
 *
 * Como lo resuelve (por cada equipo, igual que San Diego):
 *   1. Busca el equipo por fragmento + id_liga=28, confirma 1 solo match.
 *   2. Crea/reutiliza el estadio via Repository::upsertEstadio(),
 *      fuente='manual-arg-2026', external_id propio por estadio
 *      (idempotente -- correrlo 2 veces no duplica nada).
 *   3. Busca foto real en Wikipedia (misma logica que
 *      poblar_imagenes_estadios_wikipedia.php / agregar_estadio_sandiego.php).
 *   4. Vincula el estadio al equipo via Repository::upsertEquipo(),
 *      usando el MISMO external_id de ESPN que ya tiene el equipo
 *      (fuente='espn-futbol') -- upsertEquipo toma el camino de UPDATE
 *      del equipo ya existente, no crea uno nuevo.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php agregar_estadios_manual_arg.php [--aplicar]
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const ID_LIGA_ARG = 28;
const FUENTE_ESPN_FUTBOL = 'espn-futbol';
const FUENTE_ESTADIO_MANUAL = 'manual-arg-2026';

// fragmento_busqueda => [espnId, nombreEstadio, ciudad, capacidad, external_id_estadio]
$equiposPendientes = [
    'Aldosivi' => [
        'espnId' => '9739',
        'estadio' => 'Estadio José María Minella',
        'ciudad' => 'Mar del Plata, Provincia de Buenos Aires',
        'capacidad' => 35180,
        'externalIdEstadio' => 'arg-minella-aldosivi',
    ],
    'Rivadavia' => [
        'espnId' => '9744',
        'estadio' => 'Estadio Bautista Gargantini',
        'ciudad' => 'Mendoza, Provincia de Mendoza',
        'capacidad' => 24000,
        'externalIdEstadio' => 'arg-gargantini-indriv',
    ],
    'Riestra' => [
        'espnId' => '17702',
        'estadio' => 'Estadio Guillermo Laza',
        'ciudad' => 'Capital Federal, Ciudad de Buenos Aires',
        'capacidad' => 3000,
        'externalIdEstadio' => 'arg-laza-riestra',
    ],
    'Río Cuarto' => [
        'espnId' => '19685',
        'estadio' => 'Estadio Ciudad de Río Cuarto - Antonio Candini',
        'ciudad' => 'Río Cuarto, Provincia de Córdoba',
        'capacidad' => 12000,
        'externalIdEstadio' => 'arg-candini-riocuarto',
    ],
    // OJO: fragmento 'Gimnasia' solo matchearia tambien a 'Gimnasia L.P.'
    // (La Plata, liga 28 tambien) -- por eso se agrega el AND extra abajo.
    'Gimnasia' => [
        'espnId' => '11972',
        'estadio' => 'Estadio Víctor Antonio Legrotaglie',
        'ciudad' => 'Mendoza, Provincia de Mendoza',
        'capacidad' => 14000,
        'externalIdEstadio' => 'arg-legrotaglie-gimnasiamza',
        'fragmentoExtra' => 'Mendoza', // AND nombre LIKE '%Mendoza%'
    ],
];

$aplicar = in_array('--aplicar', $argv, true);

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

$creados = 0;
$actualizados = 0;
$omitidos = 0;
$errores = 0;

foreach ($equiposPendientes as $fragmento => $datos) {
    echo "--- Buscando equipo por fragmento '$fragmento' en id_liga=" . ID_LIGA_ARG . " ---\n";

    $sql = 'SELECT id, nombre, id_deporte, id_liga, id_pais, id_estadio FROM equipos WHERE id_liga = ? AND nombre LIKE ?';
    $params = [ID_LIGA_ARG, "%$fragmento%"];
    if (isset($datos['fragmentoExtra'])) {
        $sql .= ' AND nombre LIKE ?';
        $params[] = '%' . $datos['fragmentoExtra'] . '%';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($matches) !== 1) {
        fwrite(STDERR, "  OMITIDO: fragmento '$fragmento' dio " . count($matches) . " resultados (se esperaba 1). Revisar a mano.\n");
        foreach ($matches as $m) {
            fwrite(STDERR, "    -> candidato: id={$m['id']} nombre='{$m['nombre']}'\n");
        }
        $omitidos++;
        continue;
    }

    $equipo = $matches[0];
    echo "  Equipo confirmado: '{$equipo['nombre']}' (id={$equipo['id']}), id_estadio actual: " . ($equipo['id_estadio'] ?? 'NULL') . "\n";

    // Buscar foto real en Wikipedia (misma logica que poblar_imagenes_estadios_wikipedia.php)
    $titulo = buscarTituloWikipedia($http, $datos['estadio'], null, 'es');
    $urlImagen = null;
    if ($titulo !== null) {
        $resumen = obtenerResumenWikipedia($http, $titulo, 'es');
        $urlImagen = $resumen['originalimage']['source'] ?? $resumen['thumbnail']['source'] ?? null;
        if ($urlImagen !== null) {
            $urlImagen = strtok($urlImagen, '?');
        }
    }
    echo "  Pagina Wikipedia: " . ($titulo ?? 'NINGUNA') . " | Imagen: " . ($urlImagen ?? 'NINGUNA -- se guardara NULL') . "\n";

    if (!$aplicar) {
        echo "  CREARIA/ACTUALIZARIA estadio '{$datos['estadio']}' ({$datos['ciudad']}, cap. {$datos['capacidad']}) -> equipo '{$equipo['nombre']}'\n\n";
        continue;
    }

    $idEstadio = $repo->upsertEstadio(
        FUENTE_ESTADIO_MANUAL,
        $datos['estadio'],
        $datos['ciudad'],
        $equipo['id_pais'],
        $datos['capacidad'],
        $urlImagen,
        $datos['externalIdEstadio']
    );

    if ($idEstadio === null) {
        fwrite(STDERR, "  ERROR: fallo upsertEstadio para '{$equipo['nombre']}', se saltea sin tocar el equipo.\n");
        $errores++;
        continue;
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
        null, // no tocar escudo
        null,
        null,
        $datos['espnId']
    );

    echo "  Listo: '{$equipo['nombre']}' vinculado a estadio id=$idEstadio ('{$datos['estadio']}').\n\n";
    $actualizados++;
    $creados++;
}

echo "[RESUMEN] equipos procesados: " . count($equiposPendientes) . "\n";
if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}
echo "[RESUMEN] creados: $creados | actualizados: $actualizados | omitidos: $omitidos | errores: $errores\n";

$logger->resumen();

/**
 * Copiadas de poblar_imagenes_estadios_wikipedia.php / agregar_estadio_sandiego.php
 * a proposito -- scripts uno-off no comparten esta logica para no crear
 * una dependencia cruzada.
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