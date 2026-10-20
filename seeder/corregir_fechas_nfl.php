<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * CORREGIR FECHAS NFL — arregla partidos de la NFL (id_liga=20) que
 * quedaron sembrados con una fecha placeholder (no la fecha real del
 * partido) porque la fuente original no traia fecha para ese deporte.
 *
 * Como lo resuelve:
 *   1. Trae la lista de equipos NFL de ESPN y matchea contra tu tabla
 *      `equipos` por nombre (mismo criterio que el resto de seeders).
 *   2. Para el equipo LOCAL de cada partido a corregir, pide su
 *      calendario completo de la temporada (`/teams/{id}/schedule`).
 *   3. Dentro de ese calendario busca el evento donde ese equipo jugo
 *      de local (homeAway=home) contra el equipo VISITA del partido.
 *      Si hay exactamente un candidato, usa la fecha real de ESPN.
 *   4. Si no encuentra candidato o hay mas de uno (raro, pero puede
 *      pasar con rivales de division que juegan 2 veces), lo omite y
 *      lo deja listado para revision manual -- nunca elige a ciegas.
 *
 * Solo toca la columna fecha_partido. No toca marcador/estado (para
 * eso ya esta actualizar_resultados_espn.php una vez que la fecha
 * este bien y el partido ya se haya jugado).
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para escribir.
 *
 * Uso:
 *   php corregir_fechas_nfl.php <YYYY-MM-DD HH:MM:SS placeholder> <temporada> [--aplicar]
 *
 * Ejemplo (el placeholder detectado en tu base, temporada 2026):
 *   php corregir_fechas_nfl.php "2026-07-15 03:00:15" 2026
 *   php corregir_fechas_nfl.php "2026-07-15 03:00:15" 2026 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

Env::load(__DIR__ . '/.env');

$fechaPlaceholder = $argv[1] ?? null;
$temporada = $argv[2] ?? null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$fechaPlaceholder || !$temporada) {
    fwrite(STDERR, "Uso: php corregir_fechas_nfl.php \"<fecha placeholder YYYY-MM-DD HH:MM:SS>\" <temporada> [--aplicar]\n");
    fwrite(STDERR, "Ejemplo: php corregir_fechas_nfl.php \"2026-07-15 03:00:15\" 2026 --aplicar\n");
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

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

// 1. Partidos de NFL con la fecha placeholder
$stmt = $pdo->prepare(
    'SELECT p.id, p.id_equipo_local, p.id_equipo_visita, e1.nombre AS local, e2.nombre AS visita
     FROM partidos p
     JOIN equipos e1 ON p.id_equipo_local = e1.id
     JOIN equipos e2 ON p.id_equipo_visita = e2.id
     WHERE p.id_liga = 20 AND p.fecha_partido = :fecha'
);
$stmt->execute([':fecha' => $fechaPlaceholder]);
$partidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($partidos)) {
    echo "No hay partidos de id_liga=20 con fecha_partido = '$fechaPlaceholder'. Nada que hacer.\n";
    exit(0);
}

echo count($partidos) . " partidos de NFL encontrados con esa fecha placeholder.\n\n";

// 2. Lista de equipos NFL de ESPN, matchear contra tabla equipos local
$respEquipos = $http->get('https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams', []);
$equiposEspn = $respEquipos['sports'][0]['leagues'][0]['teams'] ?? null;
if (empty($equiposEspn)) {
    echo "No se pudo obtener la lista de equipos NFL de ESPN. Abortando.\n";
    exit(1);
}

// nombre_local_normalizado no hace falta: matcheamos directo nombre BD vs nombre ESPN
$indiceEspnPorLocal = []; // id_equipo local (de tu BD) => espnId
$nombresUnicosLocal = [];
foreach ($partidos as $p) {
    $nombresUnicosLocal[$p['id_equipo_local']] = $p['local'];
}

foreach ($nombresUnicosLocal as $idLocalDb => $nombreLocalDb) {
    $mejor = null;
    $mejorScore = 0.0;
    foreach ($equiposEspn as $item) {
        $eq = $item['team'];
        $nombreEspn = $eq['displayName'] ?? $eq['name'] ?? '';
        $score = scoreNombres($nombreLocalDb, $nombreEspn);
        if ($score > $mejorScore) {
            $mejorScore = $score;
            $mejor = $eq;
        }
    }
    if ($mejor !== null && $mejorScore >= 0.6) {
        $indiceEspnPorLocal[$idLocalDb] = $mejor['id'];
    } else {
        $logger->skipped("equipo local '$nombreLocalDb' sin match >=60% entre equipos NFL de ESPN");
    }
}

// 3. Por cada equipo local distinto, traer su calendario UNA sola vez
$calendarioPorEspnId = [];
foreach ($indiceEspnPorLocal as $idLocalDb => $espnId) {
    if (isset($calendarioPorEspnId[$espnId])) {
        continue;
    }
    $resp = $http->get(
        "https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{$espnId}/schedule?season={$temporada}",
        []
    );
    $calendarioPorEspnId[$espnId] = $resp['events'] ?? [];
}

// 4. Resolver cada partido contra el calendario de su equipo local
$stmtUpdate = $pdo->prepare('UPDATE partidos SET fecha_partido = :fecha WHERE id = :id');
$corregidos = 0;
$omitidos = 0;

foreach ($partidos as $p) {
    $espnId = $indiceEspnPorLocal[$p['id_equipo_local']] ?? null;
    if ($espnId === null) {
        $omitidos++;
        continue;
    }

    $eventos = $calendarioPorEspnId[$espnId] ?? [];
    $candidatos = [];

    foreach ($eventos as $evento) {
        $competidores = $evento['competitions'][0]['competitors'] ?? [];
        $propio = null;
        $rival = null;
        foreach ($competidores as $c) {
            if ((string)($c['team']['id'] ?? '') === (string)$espnId) {
                $propio = $c;
            } else {
                $rival = $c;
            }
        }
        if ($propio === null || $rival === null) {
            continue;
        }
        // El partido en tu BD tiene a este equipo como LOCAL -> en ESPN
        // debe figurar con homeAway = home para que sea el mismo fixture.
        if (($propio['homeAway'] ?? '') !== 'home') {
            continue;
        }
        $nombreRivalEspn = $rival['team']['displayName'] ?? $rival['team']['name'] ?? '';
        $score = scoreNombres($p['visita'], $nombreRivalEspn);
        if ($score >= 0.6) {
            $candidatos[] = ['fecha' => $evento['date'] ?? null, 'score' => $score];
        }
    }

    if (count($candidatos) === 0) {
        $logger->skipped("partido #{$p['id']} ({$p['local']} vs {$p['visita']}): sin partido correspondiente en el calendario ESPN {$temporada}");
        $omitidos++;
        continue;
    }
    if (count($candidatos) > 1) {
        $logger->skipped("partido #{$p['id']} ({$p['local']} vs {$p['visita']}): mas de un candidato en el calendario (rivales de division que juegan 2 veces), revision manual");
        $omitidos++;
        continue;
    }

    try {
        $fechaReal = new DateTime($candidatos[0]['fecha']);
    } catch (Exception) {
        $logger->skipped("partido #{$p['id']}: fecha invalida devuelta por ESPN");
        $omitidos++;
        continue;
    }
    $fechaSql = $fechaReal->format('Y-m-d H:i:s');

    if ($aplicar) {
        $stmtUpdate->execute([':fecha' => $fechaSql, ':id' => $p['id']]);
    }
    $logger->updated("partido #{$p['id']} {$p['local']} vs {$p['visita']} -> fecha corregida a $fechaSql");
    $corregidos++;
}

echo "\n[RESUMEN] corregidos: $corregidos | omitidos (revision manual): $omitidos\n";
if (!$aplicar) {
    echo "Esto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
}