<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR PARTIDOS NFL DESDE ESPN — repuebla `partidos` (id_liga=20)
 * con el calendario REAL de la temporada NFL pedida, para llenar el
 * hueco que dejo borrar_partidos_nfl_falsos.php (24 cruces que el
 * seed original invento a mano y que corregir_fechas_nfl.php no pudo
 * matchear contra ningun calendario real).
 *
 * Como lo resuelve:
 *   1. Trae la lista de equipos NFL de ESPN y matchea contra tu tabla
 *      `equipos` por nombre (mismo criterio que corregir_fechas_nfl.php).
 *      Si algun equipo de tu base no matchea, se lista y se aborta esa
 *      parte -- no inventa equipos nuevos.
 *   2. Por cada equipo (una sola vez, aunque tenga varios partidos),
 *      pide su calendario REAL de local via
 *      `/teams/{id}/schedule?season=X&seasontype=2` (2 = temporada
 *      regular, para no traer pretemporada ni playoffs mezclados).
 *   3. Se queda solo con los eventos donde ese equipo jugo de LOCAL
 *      (homeAway=home) para no procesar cada partido 2 veces (una
 *      por cada equipo involucrado).
 *   4. Matchea al rival por su espnId contra el mismo indice de
 *      equipos -- si el rival no esta en tu base (no deberia pasar,
 *      son los mismos 32 equipos), omite y loguea.
 *   5. Inserta/actualiza via Repository::upsertPartido() con
 *      fuente='espn' y external_id = id de evento de ESPN -- esto es
 *      SEGURO para correr varias veces: la 2da vez ya encuentra el
 *      vinculo en `external_ids` y actualiza en vez de duplicar.
 *
 * MODO POR DEFECTO: diagnostico (dry-run), no llama a Repository (no
 * escribe nada). Agregar --aplicar para escribir de verdad.
 *
 * Uso:
 *   php poblar_partidos_nfl_espn.php <temporada>
 *   php poblar_partidos_nfl_espn.php <temporada> --aplicar
 *
 * Ejemplo:
 *   php poblar_partidos_nfl_espn.php 2026
 *   php poblar_partidos_nfl_espn.php 2026 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const ID_LIGA_NFL = 20;

$temporada = $argv[1] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$temporada) {
    fwrite(STDERR, "Uso: php poblar_partidos_nfl_espn.php <temporada> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php poblar_partidos_nfl_espn.php 2026 --aplicar\n");
    exit(1);
}

function sacarAcentosNfl(string $texto): string
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

function normalizarPalabrasNfl(string $nombre): array
{
    $n = strtolower(sacarAcentosNfl($nombre));
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'the'];
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido, true));
    return array_values($palabras);
}

function scoreNombres(string $a, string $b): float
{
    $pa = normalizarPalabrasNfl($a);
    $pb = normalizarPalabrasNfl($b);
    if (empty($pa) || empty($pb)) {
        return 0.0;
    }
    $comunes = array_intersect($pa, $pb);
    $base = min(count($pa), count($pb));
    return $base === 0 ? 0.0 : count($comunes) / $base;
}

/** ESPN status.type.name -> tu enum `estado` */
function mapearEstado(string $espnStatus): string
{
    return match (true) {
        str_contains($espnStatus, 'FINAL')      => 'finalizado',
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

// 1. Equipos NFL en tu base
$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = :liga');
$stmt->execute([':liga' => ID_LIGA_NFL]);
$equiposDb = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equiposDb)) {
    echo "No hay equipos con id_liga = " . ID_LIGA_NFL . " en tu base. Nada que hacer.\n";
    exit(0);
}
echo count($equiposDb) . " equipos NFL encontrados en tu base.\n\n";

// 2. Lista de equipos NFL de ESPN
$respEquipos = $http->get('https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams', []);
$equiposEspn = $respEquipos['sports'][0]['leagues'][0]['teams'] ?? null;
if (empty($equiposEspn)) {
    echo "No se pudo obtener la lista de equipos NFL de ESPN. Abortando.\n";
    exit(1);
}

// 3. Matchear equipos DB <-> ESPN (en ambos sentidos)
$espnIdPorDbId = [];   // id equipo tu BD -> espnId
$dbIdPorEspnId = [];   // espnId -> id equipo tu BD
$sinMatch = [];

foreach ($equiposDb as $eqDb) {
    $mejor = null;
    $mejorScore = 0.0;
    foreach ($equiposEspn as $item) {
        $eq = $item['team'];
        $nombreEspn = $eq['displayName'] ?? $eq['name'] ?? '';
        $score = scoreNombres($eqDb['nombre'], $nombreEspn);
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
        $logger->skipped("equipo '{$eqDb['nombre']}' sin match >=60% entre equipos NFL de ESPN");
    }
}

if (!empty($sinMatch)) {
    echo "\nOJO: " . count($sinMatch) . " equipo(s) de tu base no matchearon con ESPN: " . implode(', ', $sinMatch) . "\n";
    echo "Esos equipos se van a omitir; revisa el nombre en tu tabla `equipos` si no es el esperado.\n\n";
}

// 4. Deporte NFL
$idDeporte = $repo->idDeportePorSlug('futbol-americano');

// 5. Por cada equipo (una vez), pedir su calendario real de local
$creariamos = 0;
$actualizariamos = 0;
$omitidos = 0;

foreach ($espnIdPorDbId as $idDbLocal => $espnIdLocal) {
    $resp = $http->get(
        "https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{$espnIdLocal}/schedule?season={$temporada}&seasontype=2",
        []
    );
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
            $logger->skipped("evento ESPN {$evento['id']}: rival '$nombreRivalEspn' (espnId $espnIdRival) no tiene equipo correspondiente en tu base");
            $omitidos++;
            continue;
        }

        $fechaSql = (new DateTime($evento['date']))->format('Y-m-d H:i:s');
        $statusName = $competicion['status']['type']['name'] ?? 'STATUS_SCHEDULED';
        $estado = mapearEstado($statusName);
        $marcadorLocal = isset($propio['score']) ? (int)$propio['score'] : null;
        $marcadorVisita = isset($rival['score']) ? (int)$rival['score'] : null;
        if ($estado !== 'finalizado' && $estado !== 'en_curso') {
            $marcadorLocal = null;
            $marcadorVisita = null;
        }
        $jornada = $evento['week']['number'] ?? null;
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
            'espn',
            $idDeporte,
            ID_LIGA_NFL,
            $idDbLocal,
            $idDbVisita,
            null,
            'Temporada Regular',
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
} else {
    echo "[RESUMEN] se crearian/actualizarian: $creariamos | omitidos: $omitidos\n";
    echo "Esto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
}

$logger->resumen();