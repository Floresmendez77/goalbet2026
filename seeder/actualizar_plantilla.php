<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * ACTUALIZAR PLANTILLA — trae la plantilla actual de un equipo/liga desde
 * football-data.org y la reconcilia con lo que ya existe en `jugadores`.
 *
 * Requisito: la liga tiene que tener el puente ya aplicado (external_ids
 * con fuente='football-data' para cada equipo). Si un equipo no tiene
 * vinculo, se omite y se lista al final -- correr primero
 * puente_equipos_aplicar.php para esa liga.
 *
 * Logica por jugador:
 *   - Sigue en el club (nombre exacto o match de apellido) -> se
 *     actualiza posicion/dorsal/edad si cambiaron, se reactiva si estaba
 *     inactivo. NO se borra nunca.
 *   - Ya no aparece en la plantilla fresca -> se DESACTIVA (activo=0),
 *     nunca se borra (para no romper estadisticas historicas).
 *   - Nuevo fichaje que no existia -> se INSERTA.
 *
 * MODO POR DEFECTO: solo diagnostico (dry-run), no escribe nada.
 * Para aplicar de verdad, correr con --aplicar
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla.php PL 15
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla.php PL 15 --aplicar
 *
 * Donde:
 *   PL = codigo de la competicion en football-data.org
 *   15 = id interno (ligas_torneos.id) de la liga HISTORICA (donde
 *        viven los equipos y sus jugadores)
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$codigoLiga = $argv[1] ?? null;
$idLigaHistorica = isset($argv[2]) ? (int)$argv[2] : null;
$aplicar = in_array('--aplicar', $argv, true);

if (!$codigoLiga || !$idLigaHistorica) {
    echo "Uso: php actualizar_plantilla.php <CODIGO_FOOTBALL_DATA> <ID_LIGA_HISTORICA> [--aplicar]\n";
    echo "Ejemplo: php actualizar_plantilla.php PL 15\n";
    exit(1);
}

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);
if (!$apiKey) {
    echo "No se encontro FOOTBALL_DATA_API_KEY en el .env.\n";
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
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '');
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

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Correr con --aplicar para ejecutar de verdad ===\n\n";

// Equipos de la liga historica
$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = ?');
$stmt->execute([$idLigaHistorica]);
$equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equipos)) {
    echo "No hay equipos en la liga historica id=$idLigaHistorica. Revisa el ID.\n";
    exit(1);
}

$stmtExternalId = $pdo->prepare(
    "SELECT external_id FROM external_ids WHERE fuente = 'football-data' AND entidad = 'equipo' AND entidad_id = ? LIMIT 1"
);
$stmtJugadoresEquipo = $pdo->prepare('SELECT id, nombre, activo FROM jugadores WHERE id_equipo = ?');
$stmtInsertar = $pdo->prepare(
    'INSERT INTO jugadores (id_equipo, nombre, posicion, dorsal, edad, nacionalidad, activo) VALUES (?, ?, ?, ?, ?, ?, 1)'
);
$stmtActualizar = $pdo->prepare(
    'UPDATE jugadores SET nombre = ?, posicion = ?, dorsal = ?, edad = ?, nacionalidad = ?, activo = 1 WHERE id = ?'
);
$stmtDesactivar = $pdo->prepare('UPDATE jugadores SET activo = 0 WHERE id = ?');

$sinVinculo = [];
$totalAltas = 0;
$totalBajas = 0;
$totalActualizados = 0;
$totalSinCambio = 0;

foreach ($equipos as $equipo) {
    $stmtExternalId->execute([$equipo['id']]);
    $idFD = $stmtExternalId->fetchColumn();

    if ($idFD === false) {
        $sinVinculo[] = $equipo;
        continue;
    }

    $resp = $http->get(
        "https://api.football-data.org/v4/teams/{$idFD}",
        ["X-Auth-Token: $apiKey"]
    );

    if ($resp === null || empty($resp['squad'])) {
        echo "  [{$equipo['nombre']}] No se pudo obtener la plantilla desde football-data (o vino vacia).\n";
        continue;
    }

    $stmtJugadoresEquipo->execute([$equipo['id']]);
    $jugadoresDb = $stmtJugadoresEquipo->fetchAll(PDO::FETCH_ASSOC);

    // Indice por nombre exacto y por apellido, solo entre los activos
    $porNombreExacto = [];
    $porApellido = [];
    foreach ($jugadoresDb as $j) {
        $porNombreExacto[$j['nombre']] = $j;
        $palabras = normalizarPalabras($j['nombre']);
        $porApellido[apellido($palabras)][] = $j;
    }

    $vistos = []; // ids de jugadores db que aparecieron en la plantilla fresca
    $altas = [];
    $actualizados = [];

    foreach ($resp['squad'] as $jugadorFD) {
        $nombreFD = $jugadorFD['name'] ?? null;
        if (!$nombreFD) {
            continue;
        }
        $posicionFD = $jugadorFD['position'] ?? null;
        $dorsalFD = $jugadorFD['shirtNumber'] ?? null;
        $edadFD = edadDesdeNacimiento($jugadorFD['dateOfBirth'] ?? null);
        $nacionalidadFD = $jugadorFD['nationality'] ?? null;

        // 1) Match exacto
        if (isset($porNombreExacto[$nombreFD])) {
            $existente = $porNombreExacto[$nombreFD];
            $vistos[$existente['id']] = true;
            $totalSinCambio++;
            continue;
        }

        // 2) Match por apellido (entre los que no fueron ya usados)
        $palabrasFD = normalizarPalabras($nombreFD);
        $apellidoFD = apellido($palabrasFD);
        $candidato = null;
        if (isset($porApellido[$apellidoFD])) {
            foreach ($porApellido[$apellidoFD] as $cand) {
                if (!isset($vistos[$cand['id']])) {
                    $candidato = $cand;
                    break;
                }
            }
        }

        if ($candidato !== null) {
            $vistos[$candidato['id']] = true;
            $actualizados[] = "{$candidato['nombre']} -> {$nombreFD}";
            if ($aplicar) {
                $stmtActualizar->execute([$nombreFD, $posicionFD, $dorsalFD, $edadFD, $nacionalidadFD, $candidato['id']]);
            }
            $totalActualizados++;
            continue;
        }

        // 3) Alta nueva
        $altas[] = $nombreFD;
        if ($aplicar) {
            $stmtInsertar->execute([$equipo['id'], $nombreFD, $posicionFD, $dorsalFD, $edadFD, $nacionalidadFD]);
        }
        $totalAltas++;
    }

    // Los que quedaron activos en la db pero NO aparecieron en la plantilla fresca -> bajas
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

    echo "--- {$equipo['nombre']} (football-data #{$idFD}) ---\n";
    echo "  Plantilla fresca: " . count($resp['squad']) . " jugadores\n";
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
echo "Jugadores sin cambio (ya estaban igual): {$totalSinCambio}\n";
echo "Jugadores actualizados (match por apellido): {$totalActualizados}\n";
echo "Altas nuevas: {$totalAltas}\n";
echo "Bajas (desactivados): {$totalBajas}\n";

if (!empty($sinVinculo)) {
    echo "\nEquipos SIN vinculo a football-data (correr puente_equipos_aplicar.php primero):\n";
    foreach ($sinVinculo as $eq) {
        echo "  - #{$eq['id']} '{$eq['nombre']}'\n";
    }
}

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}