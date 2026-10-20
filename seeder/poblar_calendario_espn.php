<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR CALENDARIO DE FUTBOL DESDE ESPN VIA SCOREBOARD POR RANGO DE FECHA
 *
 * POR QUE EXISTE (no reemplaza a poblar_partidos_liga_espn.php, lo
 * complementa): ese script trae el calendario via /teams/{id}/schedule,
 * endpoint que confirmamos (diagnostico_scoreboard_crudo.php, 15/8,
 * contra arg.1 Y usa.1) NUNCA devuelve partidos STATUS_SCHEDULED -- corta
 * en seco en la fecha de hoy en las dos ligas por igual, aunque MLS tenga
 * el calendario completo anunciado desde marzo. O sea: sirve para
 * resultados ya jugados, pero es INUTIL para traer los partidos futuros
 * que el proyecto necesita para dejar apostar a la gente.
 *
 * El endpoint que SI trae partidos futuros es el scoreboard de la LIGA
 * filtrado por rango de fechas (el mismo que alimenta espn.com/soccer
 * fixtures). Confirmado en vivo el 15/8 contra arg.1 y usa.1: un solo
 * request con "dates=YYYYMMDD-YYYYMMDD" devuelve EXACTAMENTE el mismo
 * total de eventos que sumar dia por dia (Argentina: 44 = 44 en 21 dias,
 * MLS: 75 = 75) -- asi que se puede pedir la liga entera con un request,
 * sin necesidad de loopear fecha por fecha.
 *
 * VENTAJA ESTRUCTURAL ADEMAS de traer partidos futuros: cada partido
 * viene UNA sola vez (no una vez por cada equipo como en /schedule), asi
 * que no hace falta el filtro homeAway==='home' para no duplicar. Y cada
 * evento trae el equipo LOCAL y VISITA con su team.id/displayName ya
 * embebidos -- no hace falta pedir /teams aparte para el matching, alcanza
 * con mirar external_ids primero y el nombre embebido despues, evento por
 * evento. Eso tambien esquiva de raiz el bug historico de la paginacion
 * no determinista de /teams (Godoy Cruz, Colon Santa Fe, Arsenal Sarandi).
 *
 * IDEMPOTENCIA: usa la MISMA fuente 'espn-futbol' y el mismo external_id
 * (el id numerico de evento de ESPN) que ya usa poblar_partidos_liga_espn.php.
 * Si un partido ya fue sembrado por ese script (por ejemplo, ya jugado),
 * correr este script sobre el mismo rango de fechas lo UPDATEA, no lo
 * duplica -- son el mismo id de evento en ambos endpoints de ESPN.
 *
 * CHUNKING DEFENSIVO: no se sabe si ESPN acepta un rango arbitrariamente
 * largo (solo se probo hasta 21 dias en vivo). Para no asumir de mas, este
 * script parte cualquier rango en ventanas de <= 21 dias y hace un request
 * por ventana -- barato igual (una liga con 3 meses de calendario son 4-5
 * requests, no 90).
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para escribir.
 *
 * Uso:
 *   php poblar_calendario_espn.php <id_liga_bd> <espn_slug> <fecha_inicio AAAA-MM-DD> <fecha_fin AAAA-MM-DD> [--aplicar]
 *
 * Ejemplos (Liga Profesional Argentina, resto del Clausura desde hoy):
 *   php poblar_calendario_espn.php 28 arg.1 2026-08-15 2026-12-15
 *   php poblar_calendario_espn.php 28 arg.1 2026-08-15 2026-12-15 --aplicar
 *
 * Tambien sirve para traer/actualizar lo que YA se jugo si haces falta
 * (por ejemplo re-confirmar resultados recientes):
 *   php poblar_calendario_espn.php 30 usa.1 2026-01-01 2026-12-15 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_ESPN_FUTBOL = 'espn-futbol';
const DIAS_POR_VENTANA = 21; // el maximo probado en vivo contra ESPN

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$espnSlug = $argv[2] ?? null;
$fechaInicioStr = $argv[3] ?? null;
$fechaFinStr = $argv[4] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$idLigaBd || !$espnSlug || !$fechaInicioStr || !$fechaFinStr) {
    fwrite(STDERR, "Uso: php poblar_calendario_espn.php <id_liga_bd> <espn_slug> <fecha_inicio AAAA-MM-DD> <fecha_fin AAAA-MM-DD> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php poblar_calendario_espn.php 28 arg.1 2026-08-15 2026-12-15 --aplicar\n");
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

function sacarAcentosCal(string $texto): string
{
    $mapa = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n','ø'=>'o','Ø'=>'o',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabrasCal(string $nombre): array
{
    $n = strtolower(sacarAcentosCal($nombre));
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['fc', 'cf', 'sc', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the', 'united'];
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido, true));
    return array_values($palabras);
}

function scoreNombresCal(string $a, string $b): float
{
    $pa = normalizarPalabrasCal($a);
    $pb = normalizarPalabrasCal($b);
    if (empty($pa) || empty($pb)) {
        return 0.0;
    }
    $comunes = array_intersect($pa, $pb);
    $base = min(count($pa), count($pb));
    return $base === 0 ? 0.0 : count($comunes) / $base;
}

/** Identico al de poblar_partidos_liga_espn.php -- mismo mapeo, mismo
 *  hallazgo (ESPN usa STATUS_FULL_TIME para futbol, no STATUS_FINAL). */
function mapearEstadoCal(string $espnStatus): string
{
    return match (true) {
        str_contains($espnStatus, 'FINAL'),
        str_contains($espnStatus, 'FULL_TIME')  => 'finalizado',
        str_contains($espnStatus, 'IN_PROGRESS'),
        str_contains($espnStatus, 'HALFTIME')   => 'en_curso',
        str_contains($espnStatus, 'POSTPONED')  => 'pospuesto',
        str_contains($espnStatus, 'CANCELED')   => 'cancelado',
        str_contains($espnStatus, 'SUSPENDED')  => 'suspendido',
        default                                  => 'programado',
    };
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

// 1. Equipos de tu base para esta liga puntual (unica fuente de verdad
//    para el matching -- no hace falta pedir /teams de ESPN aparte,
//    porque cada evento del scoreboard ya trae el nombre/id de ESPN de
//    sus 2 equipos embebido).
$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = :liga');
$stmt->execute([':liga' => $idLigaBd]);
$equiposDb = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equiposDb)) {
    echo "No hay equipos con id_liga = $idLigaBd en tu base. Sembra los equipos primero (este script no crea equipos).\n";
    exit(0);
}
echo count($equiposDb) . " equipos encontrados en tu base para id_liga=$idLigaBd.\n\n";

$idDeporte = $repo->idDeportePorSlug('futbol');

// 2. Armar las ventanas de <=21 dias que cubren todo el rango pedido.
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

// 3. Traer todos los eventos de todas las ventanas, deduplicando por id
//    de evento (por si dos ventanas llegaran a solaparse).
$eventosPorId = [];
foreach ($ventanas as [$desde, $hasta]) {
    $desdeC = $desde->format('Ymd');
    $hastaC = $hasta->format('Ymd');
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/scoreboard?dates={$desdeC}-{$hastaC}";
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

// 4. Cache de matching espnId -> idDb, para no repetir la consulta a
//    external_ids ni el name-scoring dos veces para el mismo equipo.
$dbIdPorEspnIdCache = [];
$sinMatch = [];

function resolverDbIdDeEquipoEspn(
    string $espnId,
    string $nombreEspn,
    array $equiposDb,
    Repository $repo,
    array &$cache,
    array &$sinMatch
): ?int {
    if (array_key_exists($espnId, $cache)) {
        return $cache[$espnId];
    }

    // Paso 1: vinculo ya conocido en external_ids (vincular_equipo_espn.php,
    // agregar_equipo_espn.php, o corridas previas de poblar_partidos_liga_espn.php).
    $idExistente = $repo->resolverExternalId(FUENTE_ESPN_FUTBOL, 'equipo', $espnId);
    if ($idExistente !== null) {
        $cache[$espnId] = $idExistente;
        return $idExistente;
    }

    // Paso 2: matching por nombre normalizado contra los equipos de tu
    // base para esta liga (>=60%, mismo umbral que poblar_partidos_liga_espn.php).
    $mejor = null;
    $mejorScore = 0.0;
    foreach ($equiposDb as $eqDb) {
        $score = scoreNombresCal($eqDb['nombre'], $nombreEspn);
        if ($score > $mejorScore) {
            $mejorScore = $score;
            $mejor = $eqDb;
        }
    }
    if ($mejor !== null && $mejorScore >= 0.6) {
        $cache[$espnId] = (int)$mejor['id'];
        return (int)$mejor['id'];
    }

    $cache[$espnId] = null;
    if (!in_array($nombreEspn, $sinMatch, true)) {
        $sinMatch[] = $nombreEspn;
    }
    return null;
}

$creariamos = 0;
$actualizariamos = 0;
$omitidos = 0;

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
        $logger->skipped("evento ESPN {$evento['id']}: no se pudo determinar local/visita (homeAway ausente o inesperado)");
        $omitidos++;
        continue;
    }

    $espnIdLocal = (string)($local['team']['id'] ?? '');
    $espnIdVisita = (string)($visita['team']['id'] ?? '');
    $nombreLocalEspn = $local['team']['displayName'] ?? $local['team']['name'] ?? '(desconocido)';
    $nombreVisitaEspn = $visita['team']['displayName'] ?? $visita['team']['name'] ?? '(desconocido)';

    $idDbLocal = resolverDbIdDeEquipoEspn($espnIdLocal, $nombreLocalEspn, $equiposDb, $repo, $dbIdPorEspnIdCache, $sinMatch);
    $idDbVisita = resolverDbIdDeEquipoEspn($espnIdVisita, $nombreVisitaEspn, $equiposDb, $repo, $dbIdPorEspnIdCache, $sinMatch);

    if ($idDbLocal === null || $idDbVisita === null) {
        $logger->skipped("evento ESPN {$evento['id']}: '$nombreLocalEspn' vs '$nombreVisitaEspn' -- uno o ambos equipos sin match en tu base para id_liga=$idLigaBd");
        $omitidos++;
        continue;
    }

    $fechaSql = (new DateTime($evento['date']))->format('Y-m-d H:i:s');
    $statusName = $competicion['status']['type']['name'] ?? $evento['status']['type']['name'] ?? 'STATUS_SCHEDULED';
    $estado = mapearEstadoCal($statusName);
    $marcadorLocal = isset($local['score']) ? (int)(is_array($local['score']) ? ($local['score']['value'] ?? 0) : $local['score']) : null;
    $marcadorVisita = isset($visita['score']) ? (int)(is_array($visita['score']) ? ($visita['score']['value'] ?? 0) : $visita['score']) : null;
    if ($estado !== 'finalizado' && $estado !== 'en_curso') {
        $marcadorLocal = null;
        $marcadorVisita = null;
    }
    // El scoreboard trae 'season.slug' (ej. 'torneo-clausura'), mas
    // confiable que el fallback generico que usa poblar_partidos_liga_espn.php
    // para /schedule (ese endpoint no siempre lo trae).
    $fase = $evento['season']['slug'] ?? ($evento['seasonType']['name'] ?? 'REGULAR_SEASON');
    $jornada = $evento['week']['number'] ?? null;
    $externalId = (string)$evento['id'];

    $nombreLocalDb = null;
    foreach ($equiposDb as $eqDb) {
        if ((int)$eqDb['id'] === $idDbLocal) {
            $nombreLocalDb = $eqDb['nombre'];
            break;
        }
    }

    if (!$aplicar) {
        $logger->info("CREARIA/ACTUALIZARIA: $nombreLocalDb vs $nombreVisitaEspn -- $fechaSql -- $estado ($fase)" .
            ($marcadorLocal !== null ? " ($marcadorLocal-$marcadorVisita)" : '') .
            " [espn:$externalId]");
        $creariamos++;
        continue;
    }

    $idResultante = $repo->upsertPartido(
        FUENTE_ESPN_FUTBOL,
        $idDeporte,
        $idLigaBd,
        $idDbLocal,
        $idDbVisita,
        null,
        $fase,
        $jornada,
        null,
        $fechaSql,
        $marcadorLocal,
        $marcadorVisita,
        $estado,
        $externalId
    );
    if ($idResultante !== null) {
        $actualizariamos++;
    } else {
        $omitidos++;
    }
}

if (!empty($sinMatch)) {
    echo "\nOJO: " . count($sinMatch) . " nombre(s) de equipo de ESPN sin match en tu base: " . implode(', ', $sinMatch) . "\n";
    echo "Si es un nombre valido pero muy distinto al de tu base, corre vincular_equipo_espn.php futbol $espnSlug <id_equipo_bd> para linkearlo a mano.\n\n";
}

echo "\n";
if ($aplicar) {
    echo "[RESUMEN] procesados: $actualizariamos | omitidos: $omitidos\n";
    echo "Recorda correr recalcular_tablas_futbol.php (o el equivalente) para esta liga si aplica.\n";
} else {
    echo "[RESUMEN] se crearian/actualizarian: $creariamos | omitidos: $omitidos\n";
    echo "Esto fue solo un diagnostico. Si el conteo y las fechas se ven bien, correr de nuevo con --aplicar.\n";
}

$logger->resumen();