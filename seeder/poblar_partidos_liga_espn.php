<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR PARTIDOS DE FUTBOL DESDE ESPN — version GENERALIZADA de
 * poblar_partidos_nfl_espn.php para cualquier liga de futbol (id_liga
 * de tu base + slug de liga de ESPN), en vez de tener el mismo codigo
 * copiado una vez por liga (MLS, Champions 2026-27, Ligue 2, Serie B,
 * Liga Profesional Argentina, Liga MX, Saudi Pro League, J1 League --
 * todo lo que quedo pendiente en el plan original).
 *
 * Primer uso real: MLS (id_liga=30 en tu base, slug ESPN 'usa.1').
 * Los equipos ya estan sembrados y linkeados (api-sports-futbol:253,
 * ver seed_2026-07-21.log) -- lo unico que falta es el calendario,
 * porque ese mismo dia /fixtures de api-sports.io rate-limiteo y nunca
 * se reintento. Esta ruta evita depender de nuevo de esa API.
 *
 * Como lo resuelve (mismo esqueleto que la version NFL):
 *   1. Trae tus equipos con id_liga = <id_liga_bd> y los matchea por
 *      nombre normalizado (>=60% de palabras en comun) contra la lista
 *      de equipos de ESPN para <espn_slug>. Nunca crea un equipo nuevo
 *      si no matchea -- se lista al final para revisar a mano.
 *   2. Por cada equipo matcheado (una sola vez), pide su calendario real
 *      de LOCAL via /teams/{espnId}/schedule?season=<temporada>, y se
 *      queda solo con homeAway=home para no procesar cada partido 2 veces.
 *   3. Matchea al rival por su espnId contra el mismo indice. Si el rival
 *      no esta en tu base bajo esta liga, omite y loguea (no inventa).
 *   4. Upsert idempotente via Repository::upsertPartido() -- 2da corrida
 *      encuentra el vinculo en external_ids y actualiza, no duplica.
 *
 * DIFERENCIA DELIBERADA vs poblar_partidos_nfl_espn.php -- fuente
 * namespaced: la version NFL usa fuente='espn' a secas (heredado, y
 * seguro ahi porque hoy es la unica liga que puebla asi). Esta version
 * generaliza a VARIAS ligas de futbol a la vez, asi que usamos
 * 'espn-futbol' -- el mismo criterio de namespacing por deporte que ya
 * documenta seeder/Seeders/EspnSeeder.php, justamente para no repetir
 * la clase de bug que ya paso una vez con api-sports (external_id de
 * un equipo de un deporte pisando el de otro deporte, ver el FIX
 * documentado arriba de ApiSportsSeeder::fuente()). Esto es un fuente
 * DISTINTO al que uso poblar_partidos_nfl_espn.php para NFL, y tambien
 * distinto al 'espn' que usa actualizar_resultados_espn.php/EspnSeeder
 * para pisar marcadores de partidos ya sembrados por otra fuente --
 * si mezclaras esos flujos sobre el mismo id_liga vas a terminar con
 * partidos duplicados (uno por external_ids con fuente 'espn-futbol',
 * otro con fuente 'espn'). Para una liga nueva (como MLS hoy) no hay
 * conflicto: no hay ningun partido previo de otra fuente para pisar.
 *
 * OJO CON EL SHAPE EXACTO DE LA RESPUESTA PARA FUTBOL: este script
 * generaliza el patron que SI se probo en produccion para NFL, pero
 * el JSON de /teams/{id}/schedule para deportes 'soccer' de ESPN no
 * se pudo validar en vivo desde este entorno (sin salida de red a
 * espn.com aca). Cosas que pueden diferir de NFL y por eso se leen
 * todas con '?? null'/fallbacks en vez de asumir la clave:
 *   - 'week.number' puede no venir (las ligas de futbol no siempre
 *     numeran jornada en este endpoint) -> jornada queda NULL, y el
 *     resto del proyecto ya tolera eso (ver el comentario de
 *     Repository::recalcularTablaPosiciones sobre partidos de
 *     api-sports, que tambien viajan siempre con jornada NULL).
 *   - No se manda '&seasontype=' por default (a diferencia de NFL) --
 *     en soccer ese parametro no es un standard confiable entre ligas.
 *     Si tu corrida de diagnostico trae de mas (ej. amistosos de
 *     pretemporada) o de menos, se puede sumar con --seasontype=N y
 *     listo, sin tocar el resto del script.
 * Por eso: CORRER SIEMPRE PRIMERO SIN --aplicar y mirar el resumen
 * antes de escribir. Si el conteo de partidos se ve raro (muy pocos,
 * muy repetido, fechas fuera de temporada), es mas facil que sea un
 * tema de esta forma-de-respuesta que un bug de matching.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_partidos_liga_espn.php <id_liga_bd> <espn_slug> <temporada> [--aplicar] [--seasontype=N]
 *
 * Ejemplo (MLS 2026, primero el diagnostico):
 *   php poblar_partidos_liga_espn.php 30 usa.1 2026
 *   php poblar_partidos_liga_espn.php 30 usa.1 2026 --aplicar
 *
 * Ejemplos para cuando sigamos con el resto del plan (mismo script,
 * sin escribir codigo nuevo por liga):
 *   php poblar_partidos_liga_espn.php  6 fra.2 2026   -- Ligue 2
 *   php poblar_partidos_liga_espn.php  7 ita.2 2026   -- Serie B
 *   php poblar_partidos_liga_espn.php 10 arg.1 2026   -- Liga Profesional Argentina
 *   php poblar_partidos_liga_espn.php 11 mex.1 2026   -- Liga MX
 *   php poblar_partidos_liga_espn.php 13 ksa.1 2026   -- Saudi Pro League
 *   php poblar_partidos_liga_espn.php 14 jpn.1 2026   -- J1 League
 * (confirmar cada slug con diagnostico_espn_crudo.php equipos <slug>
 * antes de correr esto -- no todos los slugs de ESPN son obvios).
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_ESPN_FUTBOL = 'espn-futbol';

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$espnSlug = $argv[2] ?? null;
$temporada = $argv[3] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

$seasontype = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--seasontype=')) {
        $seasontype = substr($arg, strlen('--seasontype='));
    }
}

if (!$idLigaBd || !$espnSlug || !$temporada) {
    fwrite(STDERR, "Uso: php poblar_partidos_liga_espn.php <id_liga_bd> <espn_slug> <temporada> [--aplicar] [--seasontype=N]\n");
    fwrite(STDERR, "Ejemplo: php poblar_partidos_liga_espn.php 30 usa.1 2026 --aplicar\n");
    exit(1);
}

function sacarAcentosLiga(string $texto): string
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

/** Ruido mas amplio que la version NFL a proposito: nombres de futbol
 *  traen mas sufijos/prefijos de club (FC, CF, SC, United, Club, etc.)
 *  que pueden inflar/bajar el score de coincidencia si no se filtran. */
function normalizarPalabrasLiga(string $nombre): array
{
    $n = strtolower(sacarAcentosLiga($nombre));
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['fc', 'cf', 'sc', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the', 'united'];
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido, true));
    return array_values($palabras);
}

function scoreNombresLiga(string $a, string $b): float
{
    $pa = normalizarPalabrasLiga($a);
    $pb = normalizarPalabrasLiga($b);
    if (empty($pa) || empty($pb)) {
        return 0.0;
    }
    $comunes = array_intersect($pa, $pb);
    $base = min(count($pa), count($pb));
    return $base === 0 ? 0.0 : count($comunes) / $base;
}

/** ESPN status.type.name -> tu enum `estado` (identico al resto del proyecto)
 *
 * OJO (confirmado 13/8 con diagnostico_horario_crudo.php contra Nashville
 * SC id 18986 en usa.1): a diferencia de NFL, que usa 'STATUS_FINAL',
 * ESPN devuelve 'STATUS_FULL_TIME' para un partido de SOCCER terminado.
 * La version original de este mapeo (copiada 1:1 de la de NFL) solo
 * buscaba la palabra 'FINAL', asi que todo partido de futbol ya jugado
 * caia siempre al default 'programado' sin marcador -- exactamente el
 * sintoma que se vio en el diagnostico de MLS (partidos de mayo/julio
 * con fecha pasada marcados 'programado'). Se agrega 'FULL_TIME' aparte
 * de 'FINAL' para cubrir ambos shapes sin romper NFL. */
function mapearEstadoLiga(string $espnStatus): string
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

// 1. Equipos de tu base para esta liga puntual
$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = :liga');
$stmt->execute([':liga' => $idLigaBd]);
$equiposDb = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equiposDb)) {
    echo "No hay equipos con id_liga = $idLigaBd en tu base. Sembra los equipos primero (este script no crea equipos).\n";
    exit(0);
}
echo count($equiposDb) . " equipos encontrados en tu base para id_liga=$idLigaBd.\n\n";

// 2. Lista de equipos de ESPN para ese slug
//
// OJO (encontrado corriendo esto contra arg.1): sin un 'limit' explicito,
// este endpoint de ESPN puede devolver una lista de equipos recortada/
// paginada (el tamano de pagina por default no esta documentado y no es
// confiable entre ligas) -- eso explicaba que 3 equipos vinculados a mano
// en external_ids (Godoy Cruz, Colon Santa Fe, Arsenal Sarandi) NO
// aparecieran en $equiposEspn en esta corrida, aunque vincular_equipo_espn.php
// SI los hubiera visto en otro momento (mismo endpoint, pero la paginacion
// de ESPN no es determinista). Se fuerza un limit alto para traer la liga
// completa siempre, sin importar cuantos equipos tenga.
$respEquipos = $http->get("https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/teams?limit=100", []);
$equiposEspn = $respEquipos['sports'][0]['leagues'][0]['teams'] ?? null;
if (empty($equiposEspn)) {
    echo "No se pudo obtener la lista de equipos de ESPN para '$espnSlug'. Revisa el slug con diagnostico_espn_crudo.php equipos $espnSlug. Abortando.\n";
    exit(1);
}

// 3. Matchear equipos DB <-> ESPN (en ambos sentidos)
//
// OJO (encontrado 13/8 corriendo el dry-run real de MLS): antes esta
// seccion matcheaba SOLO por nombre, sin pasar primero por
// external_ids -- al reves del flujo que documenta el resto del
// proyecto (Repository: 1. buscar en external_ids, 2. si no hay,
// matchear por nombre). Eso hacia que vincular_equipo_espn.php (que
// solo ESCRIBE en external_ids) no tuviera ningun efecto sobre este
// script en particular, aunque el comentario de mas abajo diga que
// hay que usarlo para los 'sin match' -- lo cual era falso tal como
// estaba escrito. Se agrega el paso de external_ids primero para que
// ese consejo sea real y para no depender solo del score de nombre
// (que ya se sabia iba a fallar con "LA Galaxy" / "LAFC" vs los
// nombres largos "Los Angeles Galaxy" / "Los Angeles FC" de tu base).
$espnIdPorDbId = [];
$dbIdPorEspnId = [];
$sinMatch = [];

// Indice de equipos ESPN por su id, para poder resolver rapido cuando
// external_ids ya tiene el vinculo manual.
$equiposEspnPorId = [];
foreach ($equiposEspn as $item) {
    $equiposEspnPorId[(string)$item['team']['id']] = $item['team'];
}

foreach ($equiposDb as $eqDb) {
    // Paso 1: vinculo manual ya cargado via vincular_equipo_espn.php
    //
    // OJO (encontrado corriendo esto varias veces contra arg.1, con
    // resultados identicos pese al fix de (int) y al fix de limit=100):
    // la version anterior de este paso cruzaba CADA candidato de
    // $equiposEspnPorId contra external_ids, en vez de preguntar
    // directo "este equipo de mi base, tiene vinculo manual?". Eso
    // significa que si el equipo vinculado (ej. Arsenal Sarandi,
    // espnId 2635) no viene incluido en la respuesta de /teams en ESE
    // momento puntual (la lista de ESPN no es consistente entre
    // llamadas -- comprobado: vincular_equipo_espn.php SI lo vio en
    // su momento, este script despues NO, mismo endpoint), el vinculo
    // nunca se encontraba, aun estando bien guardado en la base. Se
    // consulta directo por entidad_id -- no depende en absoluto de
    // que ESPN incluya a este equipo en /teams para reconocer el
    // vinculo manual.
    $espnIdVinculado = $repo->resolverEspnIdDeEquipo(FUENTE_ESPN_FUTBOL, (int)$eqDb['id']);
    if ($espnIdVinculado !== null) {
        $espnIdPorDbId[$eqDb['id']] = $espnIdVinculado;
        $dbIdPorEspnId[$espnIdVinculado] = $eqDb['id'];
        continue;
    }

    // Paso 2: matching por nombre normalizado (solo si no habia vinculo)
    $mejor = null;
    $mejorScore = 0.0;
    foreach ($equiposEspn as $item) {
        $eq = $item['team'];
        $nombreEspn = $eq['displayName'] ?? $eq['name'] ?? '';
        $score = scoreNombresLiga($eqDb['nombre'], $nombreEspn);
        if ($score > $mejorScore) {
            $mejorScore = $score;
            $mejor = $eq;
        }
    }
    if ($mejor !== null && $mejorScore >= 0.6) {
        $espnIdPorDbId[$eqDb['id']] = $mejor['id'];
        $dbIdPorEspnId[$mejor['id']] = $eqDb['id'];
    } else {
        $sinMatch[] = $eqDb['nombre'];
        $logger->skipped("equipo '{$eqDb['nombre']}' sin match >=60% entre equipos de ESPN ($espnSlug) y sin vinculo manual en external_ids");
    }
}

if (!empty($sinMatch)) {
    echo "\nOJO: " . count($sinMatch) . " equipo(s) de tu base no matchearon con ESPN ni tienen vinculo manual: " . implode(', ', $sinMatch) . "\n";
    echo "Esos equipos se van a omitir. Si el nombre en ESPN es muy distinto (ej. 'LA Galaxy' vs 'Los Angeles Galaxy'),\n";
    echo "corre vincular_equipo_espn.php futbol $espnSlug <id_equipo_bd> para linkearlo a mano (ahora si tiene efecto ac\u00e1), en vez de tocar el nombre en `equipos`.\n\n";
}

$idDeporte = $repo->idDeportePorSlug('futbol');

$creariamos = 0;
$actualizariamos = 0;
$omitidos = 0;

foreach ($espnIdPorDbId as $idDbLocal => $espnIdLocal) {
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/teams/{$espnIdLocal}/schedule?season={$temporada}";
    if ($seasontype !== null) {
        $url .= "&seasontype={$seasontype}";
    }
    $resp = $http->get($url, []);
    $eventos = $resp['events'] ?? [];

    foreach ($eventos as $evento) {
        $competicion = $evento['competitions'][0] ?? null;
        if ($competicion === null) {
            continue;
        }
        $competidores = $competicion['competitors'] ?? [];
        $propio = null;
        $rival = null;
        foreach ($competidores as $c) {
            if ((string)($c['team']['id'] ?? '') === (string)$espnIdLocal) {
                $propio = $c;
            } else {
                $rival = $c;
            }
        }
        if ($propio === null || $rival === null) {
            continue;
        }
        // Solo procesamos cuando este equipo es el LOCAL, para no
        // insertar cada partido 2 veces (una por cada lado).
        if (($propio['homeAway'] ?? '') !== 'home') {
            continue;
        }

        $espnIdRival = $rival['team']['id'] ?? null;
        $idDbVisita = $dbIdPorEspnId[$espnIdRival] ?? null;
        $nombreRivalEspn = $rival['team']['displayName'] ?? $rival['team']['name'] ?? '(desconocido)';
        if ($idDbVisita === null) {
            $logger->skipped("evento ESPN {$evento['id']}: rival '$nombreRivalEspn' (espnId $espnIdRival) no tiene equipo correspondiente en tu base para id_liga=$idLigaBd");
            $omitidos++;
            continue;
        }

        $fechaSql = (new DateTime($evento['date']))->format('Y-m-d H:i:s');
        $statusName = $competicion['status']['type']['name'] ?? 'STATUS_SCHEDULED';
        $estado = mapearEstadoLiga($statusName);
        $marcadorLocal = isset($propio['score']) ? (int)(is_array($propio['score']) ? ($propio['score']['value'] ?? 0) : $propio['score']) : null;
        $marcadorVisita = isset($rival['score']) ? (int)(is_array($rival['score']) ? ($rival['score']['value'] ?? 0) : $rival['score']) : null;
        if ($estado !== 'finalizado' && $estado !== 'en_curso') {
            $marcadorLocal = null;
            $marcadorVisita = null;
        }
        // 'week.number' no siempre viene para futbol (ver nota arriba) --
        // si no esta, jornada queda NULL, y el resto del proyecto ya lo
        // tolera (mismo caso que los partidos sembrados por api-sports).
        $jornada = $evento['week']['number'] ?? null;
        $fase = $evento['season']['slug'] ?? ($evento['seasonType']['name'] ?? 'REGULAR_SEASON');
        $externalId = (string)$evento['id'];

        $nombreLocalDb = null;
        foreach ($equiposDb as $eqDb) {
            if ($eqDb['id'] === $idDbLocal) {
                $nombreLocalDb = $eqDb['nombre'];
                break;
            }
        }

        if (!$aplicar) {
            $logger->info("CREARIA/ACTUALIZARIA: $nombreLocalDb vs $nombreRivalEspn -- $fechaSql -- $estado" .
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