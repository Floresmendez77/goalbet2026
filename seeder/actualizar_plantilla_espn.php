<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * ACTUALIZAR PLANTILLA (ESPN) — v2, busca match en TODOS los equipos de
 * futbol de la base, no solo los de la liga historica indicada.
 *
 * Motivo del cambio: equipos grandes (Real Madrid, Barcelona, Atletico
 * Madrid, y el equivalente en otras ligas) viven con id_liga=26
 * (Champions) en vez de su liga domestica, porque juegan ambos
 * torneos (mismo patron ya visto con Arsenal/Man City en Premier).
 * Si el matching solo buscara en id_liga=16, estos quedarian afuera.
 *
 * Logica por equipo de ESPN:
 *   - Busca el mejor match de nombre entre TODOS los equipos de futbol
 *     (id_deporte=1) de la base, no solo los de la liga objetivo.
 *   - Si encuentra match (score >= 0.6): actualiza sus jugadores, sin
 *     importar en que id_liga vive actualmente el equipo (no se mueve).
 *   - Si NO encuentra match en ningun lado: se asume alta nueva genuina
 *     (equipo recien ascendido que nunca existio en la base). NO se
 *     crea solo -- se lista al final para decidir a mano si conviene
 *     darlo de alta.
 *
 * Logica por jugador (igual que antes):
 *   - Nombre exacto o match de apellido -> actualiza, no borra.
 *   - No aparece en la plantilla fresca -> DESACTIVA (activo=0).
 *   - Nuevo fichaje -> INSERTA.
 *
 * MODO POR DEFECTO: solo diagnostico (dry-run).
 * Para aplicar de verdad, correr con --aplicar
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla_espn.php esp.1 16
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla_espn.php esp.1 16 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

$ligaEspn = $argv[1] ?? null;
$idLigaHistorica = isset($argv[2]) ? (int)$argv[2] : null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$ligaEspn || !$idLigaHistorica) {
    echo "Uso: php actualizar_plantilla_espn.php <slug_liga_espn> <ID_LIGA_HISTORICA> [--aplicar]\n";
    echo "Ejemplo: php actualizar_plantilla_espn.php esp.1 16\n";
    exit(1);
}

function sacarAcentos(string $texto): string
{
    $mapa = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n',
        'ø'=>'o','Ø'=>'o',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabras(string $nombre): array
{
    $n = sacarAcentos($nombre);
    $n = strtolower($n);
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the'];
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido));
    return array_values($palabras);
}

function apellido(array $palabras): string
{
    for ($i = count($palabras) - 1; $i >= 0; $i--) {
        if (strlen($palabras[$i]) >= 2) {
            return $palabras[$i];
        }
    }
    return end($palabras) ?: '';
}

function mejorCandidatoEquipo(array $palabrasEspn, array $indiceExistentes): ?array
{
    $mejor = null;
    $mejorScore = 0;
    foreach ($indiceExistentes as $existente) {
        $palabrasEx = normalizarPalabras($existente['nombre']);
        if (empty($palabrasEx) || empty($palabrasEspn)) {
            continue;
        }
        $comunes = array_intersect($palabrasEspn, $palabrasEx);
        $base = min(count($palabrasEspn), count($palabrasEx));
        if ($base === 0) {
            continue;
        }
        $score = count($comunes) / $base;
        if ($score > $mejorScore) {
            $mejorScore = $score;
            $mejor = $existente;
        }
    }
    return $mejor !== null && $mejorScore >= 0.6 ? [$mejor, $mejorScore] : null;
}

function edadDesdeNacimiento(?string $fechaNacimiento): ?int
{
    if (!$fechaNacimiento) {
        return null;
    }
    try {
        $nacimiento = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        return $hoy->diff($nacimiento)->y;
    } catch (Throwable $e) {
        return null;
    }
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Correr con --aplicar para ejecutar de verdad ===\n\n";

// IMPORTANTE: buscamos entre TODOS los equipos de futbol (id_deporte=1),
// no solo los de la liga objetivo -- para encontrar equipos grandes que
// viven con id_liga=26 (Champions) por jugar ambos torneos.
$stmt = $pdo->query('SELECT id, nombre, id_liga FROM equipos WHERE id_deporte = 1');
$todosLosEquiposFutbol = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($todosLosEquiposFutbol)) {
    echo "No hay equipos de futbol (id_deporte=1) en la base. Algo esta mal.\n";
    exit(1);
}

// Equipos "de la liga objetivo" solo para saber cuales ya viven ahi
// (informativo en el log, no restringe el matching)
$idsEnLigaObjetivo = array_flip(array_map(
    fn($e) => $e['id'],
    array_filter($todosLosEquiposFutbol, fn($e) => (int)$e['id_liga'] === $idLigaHistorica)
));

$respEquipos = $http->get("https://site.api.espn.com/apis/site/v2/sports/soccer/{$ligaEspn}/teams", []);

$equiposEspn = $respEquipos['sports'][0]['leagues'][0]['teams'] ?? null;
if (empty($equiposEspn)) {
    echo "No se pudo obtener la lista de equipos de ESPN para '$ligaEspn'. Revisa el slug.\n";
    exit(1);
}

// FIX (15/8): el mismo bug de paginacion no deterministica de /teams que
// ya se arreglo en poblar_partidos_liga_espn.php via
// resolverEspnIdDeEquipo() -- si un equipo vinculado a mano
// (vincular_equipo_espn.php) no viene en ESTA respuesta puntual de
// /teams, el Paso 1 de abajo nunca llega a consultarlo porque el loop
// principal recorre $equiposEspn, no la base local. Antes de ese loop,
// completamos $equiposEspn con cualquier equipo de la liga objetivo que
// tenga vinculo manual guardado y no haya venido en esta respuesta --
// el roster se pide despues directo por id de ESPN, no depende de que
// /teams lo haya listado.
$idsEspnYaEnLista = [];
foreach ($equiposEspn as $item) {
    $eid = $item['team']['id'] ?? null;
    if ($eid !== null) {
        $idsEspnYaEnLista[(string)$eid] = true;
    }
}

$equiposLigaObjetivo = array_values(array_filter(
    $todosLosEquiposFutbol,
    fn($e) => (int)$e['id_liga'] === $idLigaHistorica
));

foreach ($equiposLigaObjetivo as $eqLocal) {
    $espnIdVinculado = $repo->resolverEspnIdDeEquipo('espn-futbol', (int)$eqLocal['id']);
    if ($espnIdVinculado !== null && !isset($idsEspnYaEnLista[$espnIdVinculado])) {
        $equiposEspn[] = [
            'team' => [
                'id' => $espnIdVinculado,
                // nombre informativo -- el vinculo manual del Paso 1 de
                // abajo resuelve el equipo local con score=1.0, este
                // nombre solo se usa para el log y para pedir el roster.
                'displayName' => $eqLocal['nombre'],
            ],
        ];
        $idsEspnYaEnLista[$espnIdVinculado] = true;
    }
}

$stmtJugadoresEquipo = $pdo->prepare('SELECT id, nombre, activo FROM jugadores WHERE id_equipo = ?');
$stmtInsertar = $pdo->prepare(
    'INSERT INTO jugadores (id_equipo, nombre, posicion, dorsal, edad, nacionalidad, activo) VALUES (?, ?, ?, ?, ?, ?, 1)'
);
$stmtActualizar = $pdo->prepare(
    'UPDATE jugadores SET nombre = ?, posicion = ?, dorsal = ?, edad = ?, nacionalidad = ?, activo = 1 WHERE id = ?'
);
$stmtDesactivar = $pdo->prepare('UPDATE jugadores SET activo = 0 WHERE id = ?');

$sinMatch = [];
$totalAltas = 0;
$totalBajas = 0;
$totalActualizados = 0;
$totalSinCambio = 0;

$indiceEquiposPorId = [];
foreach ($todosLosEquiposFutbol as $eq) {
    $indiceEquiposPorId[$eq['id']] = $eq;
}

foreach ($equiposEspn as $item) {
    $eqEspn = $item['team'];
    $nombreEspn = $eqEspn['displayName'] ?? $eqEspn['name'] ?? '';
    $idEspn = $eqEspn['id'] ?? null;

    // Paso 1: vinculo manual ya cargado via vincular_equipo_espn.php
    // (fuente='espn-futbol', mismo namespace que usa este script y
    // poblar_partidos_liga_espn.php). Antes esto NO se consultaba, asi
    // que un equipo vinculado a mano (ej. "LA Galaxy" -> "Los Angeles
    // Galaxy", que no comparten ninguna palabra normalizada y por eso
    // dan 0% de score) seguia saliendo como "sin match" aca tambien,
    // aunque poblar_partidos_liga_espn.php ya lo encontrara bien.
    $idEntidadVinculada = $idEspn !== null
        ? $repo->resolverExternalId('espn-futbol', 'equipo', (string)$idEspn)
        : null;

    if ($idEntidadVinculada !== null && isset($indiceEquiposPorId[$idEntidadVinculada])) {
        $equipoLocal = $indiceEquiposPorId[$idEntidadVinculada];
        $score = 1.0; // vinculo manual = confianza total, no es un "match" de nombre
    } else {
        $palabrasEspn = normalizarPalabras($nombreEspn);

        // FIX (15/8): antes esto buscaba directo en TODOS los equipos de
        // futbol, lo que causaba colisiones de nombre entre equipos
        // homonimos de otra liga -- ej. "Racing Club" (Argentina, ESPN)
        // matcheaba 100% contra "Real Racing Club de Santander" (España,
        // id_liga=2) porque "club" esta en la lista de ruido y a los dos
        // nombres les queda la misma palabra "racing" sola, que da
        // score=1.0 contra si misma sin importar el resto del nombre.
        // Ahora primero se busca SOLO dentro de la liga objetivo -- ahi
        // aparece el Racing Club de Avellaneda real, y gana ese match
        // antes de mirar el resto de la base. Recien si no hay nada
        // bueno en la liga objetivo se cae al catalogo completo (asi se
        // siguen encontrando los equipos grandes que viven en id_liga=26
        // por jugar Champions, que es el motivo original de la busqueda
        // global).
        $candidato = mejorCandidatoEquipo($palabrasEspn, $equiposLigaObjetivo);
        if ($candidato === null) {
            $candidato = mejorCandidatoEquipo($palabrasEspn, $todosLosEquiposFutbol);
        }

        if ($candidato === null) {
            $sinMatch[] = $nombreEspn;
            continue;
        }

        [$equipoLocal, $score] = $candidato;
    }

    $pct = round($score * 100);
    $notaLiga = isset($idsEnLigaObjetivo[$equipoLocal['id']])
        ? ''
        : " [vive en id_liga={$equipoLocal['id_liga']}, no en {$idLigaHistorica} -- no se mueve]";

    $respRoster = $http->get(
        "https://site.api.espn.com/apis/site/v2/sports/soccer/{$ligaEspn}/teams/{$idEspn}/roster",
        []
    );

    if ($respRoster === null || empty($respRoster['athletes'])) {
        echo "  [{$equipoLocal['nombre']}] ({$nombreEspn}, match {$pct}%){$notaLiga} No se pudo obtener el roster desde ESPN (o vino vacio).\n";
        continue;
    }

    $stmtJugadoresEquipo->execute([$equipoLocal['id']]);
    $jugadoresDb = $stmtJugadoresEquipo->fetchAll(PDO::FETCH_ASSOC);

    $porNombreExacto = [];
    $porApellido = [];
    foreach ($jugadoresDb as $j) {
        $porNombreExacto[$j['nombre']] = $j;
        $palabras = normalizarPalabras($j['nombre']);
        $porApellido[apellido($palabras)][] = $j;
    }

    $vistos = [];
    $altas = [];
    $actualizados = [];

    foreach ($respRoster['athletes'] as $atleta) {
        $nombreJugador = $atleta['fullName'] ?? $atleta['displayName'] ?? null;
        if (!$nombreJugador) {
            continue;
        }
        $posicion = $atleta['position']['displayName'] ?? null;
        $dorsal = $atleta['jersey'] ?? null;
        $edad = $atleta['age'] ?? edadDesdeNacimiento($atleta['dateOfBirth'] ?? null);
        $nacionalidad = $atleta['birthPlace']['country'] ?? null;

        if (isset($porNombreExacto[$nombreJugador])) {
            $existente = $porNombreExacto[$nombreJugador];
            $vistos[$existente['id']] = true;
            $totalSinCambio++;
            continue;
        }

        $palabrasJugador = normalizarPalabras($nombreJugador);
        $apellidoJugador = apellido($palabrasJugador);
        $candidatoJug = null;
        if (isset($porApellido[$apellidoJugador])) {
            foreach ($porApellido[$apellidoJugador] as $cand) {
                if (!isset($vistos[$cand['id']])) {
                    $candidatoJug = $cand;
                    break;
                }
            }
        }

        if ($candidatoJug !== null) {
            $vistos[$candidatoJug['id']] = true;
            $actualizados[] = "{$candidatoJug['nombre']} -> {$nombreJugador}";
            if ($aplicar) {
                $stmtActualizar->execute([$nombreJugador, $posicion, $dorsal, $edad, $nacionalidad, $candidatoJug['id']]);
            }
            $totalActualizados++;
            continue;
        }

        $altas[] = $nombreJugador;
        if ($aplicar) {
            $stmtInsertar->execute([$equipoLocal['id'], $nombreJugador, $posicion, $dorsal, $edad, $nacionalidad]);
        }
        $totalAltas++;
    }

    $bajas = [];
    foreach ($jugadoresDb as $j) {
        if ((int)$j['activo'] === 1 && !isset($vistos[$j['id']])) {
            $bajas[] = $j['nombre'];
            if ($aplicar) {
                $stmtDesactivar->execute([$j['id']]);
            }
            $totalBajas++;
        }
    }

    echo "--- {$equipoLocal['nombre']} <-> ESPN '{$nombreEspn}' (match {$pct}%, id ESPN {$idEspn}){$notaLiga} ---\n";
    echo "  Plantilla fresca: " . count($respRoster['athletes']) . " jugadores\n";
    if (!empty($altas)) {
        echo "  ALTAS (" . count($altas) . "): " . implode(', ', $altas) . "\n";
    }
    if (!empty($actualizados)) {
        echo "  RENOMBRADOS/match apellido (" . count($actualizados) . "): " . implode(' | ', $actualizados) . "\n";
    }
    if (!empty($bajas)) {
        echo "  BAJAS -> desactivados (" . count($bajas) . "): " . implode(', ', $bajas) . "\n";
    }
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "Jugadores sin cambio: {$totalSinCambio}\n";
echo "Jugadores actualizados (match por apellido): {$totalActualizados}\n";
echo "Altas nuevas: {$totalAltas}\n";
echo "Bajas (desactivados): {$totalBajas}\n";

if (!empty($sinMatch)) {
    echo "\nEquipos de ESPN SIN match en NINGUNA parte de la base (posible alta nueva genuina, no se crea solo):\n";
    foreach ($sinMatch as $n) {
        echo "  - $n\n";
    }
}

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}