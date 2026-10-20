<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR CALENDARIO + PAIS + ESTADIO DE MLB DESDE ESPN
 *
 * POR QUE ESTE SCRIPT (y no api-sports): diagnostico_mlb_crudo.php
 * confirmo que api-sports para beisbol (a) tiene tope de temporada
 * 2022-2024 en el plan gratis -- nunca da la 2026 -- y (b) no tiene
 * NINGUN endpoint de venues (estadios) en absoluto (confirmado contra
 * la lista oficial de endpoints de su documentacion). ESPN si expone
 * ambas cosas para MLB, confirmado en vivo con
 * diagnostico_mlb_espn_resumen.php:
 *   - El scoreboard trae partidos con status=STATUS_SCHEDULED para
 *     fechas futuras (15 eventos confirmados para 2026-08-16).
 *   - CADA evento trae el estadio COMPLETO anidado en
 *     competitions[0].venue (id, fullName, address.city,
 *     address.state, indoor) -- no hace falta pedir /teams ni
 *     /teams/<id> por separado, el venue viene gratis en cada partido.
 *
 * IDEMPOTENCIA EQUIPOS: los 30 equipos de MLB ya estaban sembrados
 * (via api-sports-baseball) ANTES de esta sesion, y el puente
 * ESPN->equipo YA EXISTE en external_ids (fuente='espn-beisbol',
 * entidad='equipo', 30 filas, sembrado en una sesion anterior que uso
 * este mismo scoreboard para actualizar resultados historicos). Este
 * script NO crea equipos nuevos -- si un id de ESPN no tiene vinculo
 * en external_ids, se omite y se loguea (mismo criterio defensivo que
 * poblar_calendario_espn.php para futbol).
 *
 * PAIS: casi todos los equipos de MLB son de USA (ya existe en
 * paises, id conocido). Toronto Blue Jays es la UNICA excepcion --
 * juega en Canada, y 'Canada' no existe todavia en la tabla paises.
 * Este script la crea la primera vez que aparece (fuente='espn-beisbol',
 * external_id='CA', sin necesidad de pegarle a ninguna API de paises
 * aparte -- el nombre/codigo se hardcodea aca porque es un caso
 * unico conocido, mismo criterio que los overrides de estadios de
 * sesiones anteriores).
 *
 * ESTADIO: se toma el 'venue' embebido en cada evento y se vincula
 * como estadio LOCAL del equipo que jugo de home en ese partido (el
 * venue de un evento de local siempre es el estadio de ese equipo).
 * Capacidad: ESPN no la trae en este endpoint -- queda en NULL/0,
 * mejora futura pendiente si hace falta (mismo patron que otros
 * estadios sin capacidad confirmada). Imagen: tampoco la trae ESPN --
 * correr poblar_imagenes_estadios_wikipedia.php DESPUES de este
 * script para completar fotos, exactamente igual que se hizo para las
 * 5 ligas de futbol nuevas (ese script ya funciona para CUALQUIER
 * liga por id_liga_bd, no hace falta tocarlo).
 *
 * CHUNKING: mismo criterio que poblar_calendario_espn.php -- ventanas
 * de <=21 dias por request, sin asumir que ESPN acepte rangos mas
 * largos sin probarlo.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_calendario_mlb_espn.php <fecha_inicio AAAA-MM-DD> <fecha_fin AAAA-MM-DD> [--aplicar]
 *
 * Ejemplo (resto de la temporada regular 2026 desde hoy):
 *   php poblar_calendario_mlb_espn.php 2026-08-16 2026-11-05
 *   php poblar_calendario_mlb_espn.php 2026-08-16 2026-11-05 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_ESPN_MLB = 'espn-beisbol';
const ID_LIGA_MLB_BD = 19;
const DIAS_POR_VENTANA = 21;

$fechaInicioStr = $argv[1] ?? null;
$fechaFinStr = $argv[2] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$fechaInicioStr || !$fechaFinStr) {
    fwrite(STDERR, "Uso: php poblar_calendario_mlb_espn.php <fecha_inicio AAAA-MM-DD> <fecha_fin AAAA-MM-DD> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php poblar_calendario_mlb_espn.php 2026-08-16 2026-11-05 --aplicar\n");
    exit(1);
}

try {
    $fechaInicio = new DateTime($fechaInicioStr);
    $fechaFin = new DateTime($fechaFinStr);
} catch (Exception $e) {
    fwrite(STDERR, "Fecha invalida. Usa el formato AAAA-MM-DD.\n");
    exit(1);
}
if ($fechaFin < $fechaInicio) {
    fwrite(STDERR, "fecha_fin no puede ser anterior a fecha_inicio.\n");
    exit(1);
}

/** Mapeo de estados de ESPN a los que usa la base. Se incluye
 * STATUS_RAIN_DELAY porque es un estado especifico de beisbol que no
 * existe en el mapeo generico de futbol -- se trata igual que
 * pospuesto (el partido no esta jugandose en este momento). */
function mapearEstadoMlb(string $espnStatus): string
{
    return match (true) {
        str_contains($espnStatus, 'FINAL')       => 'finalizado',
        str_contains($espnStatus, 'IN_PROGRESS') => 'en_curso',
        str_contains($espnStatus, 'RAIN_DELAY'),
        str_contains($espnStatus, 'DELAYED'),
        str_contains($espnStatus, 'POSTPONED')   => 'pospuesto',
        str_contains($espnStatus, 'CANCELED')    => 'cancelado',
        str_contains($espnStatus, 'SUSPENDED')   => 'suspendido',
        default                                   => 'programado',
    };
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

$idDeporte = $repo->idDeportePorSlug('beisbol');

// Resolver (y crear si hace falta) el pais Canada para Toronto Blue
// Jays -- unico caso conocido de equipo de MLB fuera de USA. Se
// resuelve UNA vez al principio, se reusa para todo el resto de la
// corrida en vez de intentar crearlo en cada evento.
// Se busca directo por nombre (no por external_id de api-sports-baseball)
// porque la liga MLB se creo el 13/jul, ANTES de que ApiSportsSeeder
// tuviera el fix que extrae y vincula el pais (agregado el 21/jul) --
// es decir, es muy probable que ese vinculo nunca se haya creado para
// esta fuente puntual. 'USA' ya existe en la tabla (creado al resolver
// el pais de alguna liga de futbol), asi que alcanza con buscarlo por
// nombre directamente.
$stmt = $pdo->prepare("SELECT id FROM paises WHERE nombre = 'USA' LIMIT 1");
$stmt->execute();
$idPaisUsa = $stmt->fetchColumn() ?: null;
$idPaisCanada = null; // se resuelve mas abajo, solo si aparece Toronto y estamos en modo --aplicar

echo "id_pais USA resuelto: " . ($idPaisUsa ?? '(no encontrado -- revisar tabla paises a mano)') . "\n\n";

// 1. Puente ESPN -> equipo, ya sembrado en una sesion anterior.
$stmt = $pdo->prepare("SELECT entidad_id, external_id FROM external_ids WHERE fuente = ? AND entidad = 'equipo'");
$stmt->execute([FUENTE_ESPN_MLB]);
$puenteEspnEquipo = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $puenteEspnEquipo[$fila['external_id']] = (int)$fila['entidad_id'];
}
echo count($puenteEspnEquipo) . " equipo(s) con vinculo ESPN ya existente en external_ids.\n";
if (empty($puenteEspnEquipo)) {
    echo "ADVERTENCIA: no hay ningun vinculo. Si esto es inesperado, confirma que la fuente\n";
    echo "'" . FUENTE_ESPN_MLB . "' sea la correcta con un SELECT manual antes de seguir.\n";
}

// Nombres actuales en la base, para loguear con nombre real (no solo id).
$stmt = $pdo->prepare('SELECT id, nombre, id_estadio FROM equipos WHERE id_liga = ?');
$stmt->execute([ID_LIGA_MLB_BD]);
$equiposDb = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $equiposDb[(int)$fila['id']] = $fila;
}
echo count($equiposDb) . " equipos encontrados en tu base para id_liga=" . ID_LIGA_MLB_BD . ".\n\n";

// 2. Ventanas de <=21 dias.
$ventanas = [];
$cursor = clone $fechaInicio;
while ($cursor <= $fechaFin) {
    $finVentana = (clone $cursor)->modify('+' . (DIAS_POR_VENTANA - 1) . ' days');
    if ($finVentana > $fechaFin) {
        $finVentana = clone $fechaFin;
    }
    $ventanas[] = [clone $cursor, clone $finVentana];
    $cursor = (clone $finVentana)->modify('+1 day');
}
echo "Rango $fechaInicioStr a $fechaFinStr dividido en " . count($ventanas) . " ventana(s) de request.\n\n";

// 3. Traer todos los eventos, deduplicando por id.
$eventosPorId = [];
foreach ($ventanas as [$desde, $hasta]) {
    $desdeC = $desde->format('Ymd');
    $hastaC = $hasta->format('Ymd');
    $url = "https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard?dates={$desdeC}-{$hastaC}";
    $resp = $http->get($url, []);
    $eventos = $resp['events'] ?? [];
    echo "Ventana {$desde->format('Y-m-d')} a {$hasta->format('Y-m-d')}: " . count($eventos) . " evento(s).\n";
    foreach ($eventos as $ev) {
        $eventosPorId[$ev['id']] = $ev;
    }
}
echo "\nTotal de eventos unicos en el rango: " . count($eventosPorId) . "\n\n";

if (empty($eventosPorId)) {
    echo "No hay partidos en ese rango de fechas segun ESPN. Nada para procesar.\n";
    exit(0);
}

// Cache de estadios ya resueltos en esta corrida (por venue id de ESPN),
// para no reprocesar el mismo estadio partido tras partido.
$estadioCache = [];
$sinMatchEquipo = [];
$creariamos = 0;
$actualizariamos = 0;
$omitidos = 0;
$estadiosVinculados = 0;

foreach ($eventosPorId as $evento) {
    $competicion = $evento['competitions'][0] ?? null;
    if ($competicion === null) {
        continue;
    }
    $competidores = $competicion['competitors'] ?? [];
    $local = null;
    $visita = null;
    foreach ($competidores as $c) {
        if (($c['homeAway'] ?? '') === 'home') {
            $local = $c;
        } elseif (($c['homeAway'] ?? '') === 'away') {
            $visita = $c;
        }
    }
    if ($local === null || $visita === null) {
        $logger->skipped("evento ESPN {$evento['id']}: no se pudo determinar local/visita");
        $omitidos++;
        continue;
    }

    $espnIdLocal = (string)($local['team']['id'] ?? '');
    $espnIdVisita = (string)($visita['team']['id'] ?? '');
    $nombreLocalEspn = $local['team']['displayName'] ?? '(desconocido)';
    $nombreVisitaEspn = $visita['team']['displayName'] ?? '(desconocido)';

    $idDbLocal = $puenteEspnEquipo[$espnIdLocal] ?? null;
    $idDbVisita = $puenteEspnEquipo[$espnIdVisita] ?? null;

    if ($idDbLocal === null || $idDbVisita === null) {
        $faltante = $idDbLocal === null ? $nombreLocalEspn : $nombreVisitaEspn;
        if (!in_array($faltante, $sinMatchEquipo, true)) {
            $sinMatchEquipo[] = $faltante;
        }
        $logger->skipped("evento ESPN {$evento['id']}: '$nombreLocalEspn' vs '$nombreVisitaEspn' -- sin vinculo ESPN->equipo para uno de los dos");
        $omitidos++;
        continue;
    }

    // --- Estadio (solo se resuelve una vez por venue, cacheado) ---
    $venue = $competicion['venue'] ?? null;
    $idEstadio = null;
    if ($venue !== null && isset($venue['id'])) {
        $venueId = (string)$venue['id'];
        if (isset($estadioCache[$venueId])) {
            $idEstadio = $estadioCache[$venueId];
        } else {
            $nombreEstadio = $venue['fullName'] ?? null;
            $ciudad = $venue['address']['city'] ?? null;
            $estado = $venue['address']['state'] ?? null;
            $ciudadCompleta = $estado ? "$ciudad, $estado" : $ciudad;

            // Toronto Blue Jays es el unico equipo de MLB fuera de USA.
            // Se detecta por el nombre del equipo local (mas confiable
            // que intentar inferirlo de la direccion del venue, que no
            // trae 'country' en este endpoint).
            $esCanada = str_contains($nombreLocalEspn, 'Blue Jays');
            $idPaisEstadio = $idPaisUsa;
            if ($esCanada) {
                if ($idPaisCanada === null && $aplicar) {
                    $idPaisCanada = $repo->upsertPais(FUENTE_ESPN_MLB, 'Canada', 'CA', null, 'CA');
                }
                $idPaisEstadio = $idPaisCanada ?? $idPaisUsa; // en dry-run no se crea, se usa USA como placeholder solo para el log
            }

            if (!$aplicar) {
                $logger->info("ESTADIO: '$nombreEstadio' ($ciudadCompleta)" . ($esCanada ? " [CANADA]" : "") . " -- se crearia/actualizaria [espn-venue:$venueId]");
                $estadioCache[$venueId] = null; // no hay id real en dry-run
            } else {
                $idEstadio = $repo->upsertEstadio(
                    FUENTE_ESPN_MLB,
                    $nombreEstadio,
                    $ciudadCompleta,
                    $idPaisEstadio,
                    null,
                    null,
                    $venueId
                );
                $estadioCache[$venueId] = $idEstadio;
                if ($idEstadio !== null) {
                    $estadiosVinculados++;
                    // Vincular el estadio Y el pais al equipo LOCAL (el
                    // venue de un evento de local es su estadio propio).
                    // upsertEquipo hace UPDATE si el equipo ya existe,
                    // preservando el resto de sus campos.
                    $eqActual = $equiposDb[$idDbLocal] ?? null;
                    if ($eqActual !== null && (int)($eqActual['id_estadio'] ?? 0) !== $idEstadio) {
                        $stmtUpd = $pdo->prepare('UPDATE equipos SET id_estadio = ?, id_pais = ? WHERE id = ?');
                        $stmtUpd->execute([$idEstadio, $idPaisEstadio, $idDbLocal]);
                        $equiposDb[$idDbLocal]['id_estadio'] = $idEstadio;
                    }
                }
            }
        }
    }

    // --- Partido ---
    $fechaSql = (new DateTime($evento['date']))->format('Y-m-d H:i:s');
    $statusName = $competicion['status']['type']['name'] ?? 'STATUS_SCHEDULED';
    $estadoPartido = mapearEstadoMlb($statusName);
    $marcadorLocal = isset($local['score']) ? (int)(is_array($local['score']) ? ($local['score']['value'] ?? 0) : $local['score']) : null;
    $marcadorVisita = isset($visita['score']) ? (int)(is_array($visita['score']) ? ($visita['score']['value'] ?? 0) : $visita['score']) : null;
    if ($estadoPartido !== 'finalizado' && $estadoPartido !== 'en_curso') {
        $marcadorLocal = null;
        $marcadorVisita = null;
    }
    $externalId = (string)$evento['id'];
    $nombreLocalDb = $equiposDb[$idDbLocal]['nombre'] ?? $nombreLocalEspn;
    $nombreVisitaDb = $equiposDb[$idDbVisita]['nombre'] ?? $nombreVisitaEspn;

    if (!$aplicar) {
        $logger->info("CREARIA/ACTUALIZARIA: $nombreLocalDb vs $nombreVisitaDb -- $fechaSql -- $estadoPartido" .
            ($marcadorLocal !== null ? " ($marcadorLocal-$marcadorVisita)" : '') .
            " [espn:$externalId]");
        $creariamos++;
        continue;
    }

    $idResultante = $repo->upsertPartido(
        FUENTE_ESPN_MLB,
        $idDeporte,
        ID_LIGA_MLB_BD,
        $idDbLocal,
        $idDbVisita,
        $idEstadio,
        'Temporada Regular',
        null,
        null,
        $fechaSql,
        $marcadorLocal,
        $marcadorVisita,
        $estadoPartido,
        $externalId
    );
    if ($idResultante !== null) {
        $actualizariamos++;
    } else {
        $omitidos++;
    }
}

if (!empty($sinMatchEquipo)) {
    echo "\nOJO: " . count($sinMatchEquipo) . " equipo(s) de ESPN sin vinculo en external_ids: " . implode(', ', $sinMatchEquipo) . "\n";
    echo "Esto NO deberia pasar si los 30 equipos ya tenian su vinculo espn-beisbol -- revisar a mano.\n\n";
}

echo "\n";
if ($aplicar) {
    echo "[RESUMEN] partidos procesados: $actualizariamos | omitidos: $omitidos | estadios vinculados: $estadiosVinculados\n";
    echo "Recorda correr recalcular_tablas_futbol.php (o el equivalente de beisbol si existe) para esta liga.\n";
    echo "Recorda tambien correr poblar_imagenes_estadios_wikipedia.php 19 --aplicar despues, para las fotos de los estadios.\n";
} else {
    echo "[RESUMEN] se crearian/actualizarian: $creariamos | omitidos: $omitidos\n";
    echo "Esto fue solo un diagnostico. Si el conteo y las fechas se ven bien, correr de nuevo con --aplicar.\n";
}

$logger->resumen();