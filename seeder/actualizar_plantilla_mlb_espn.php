<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * ACTUALIZAR PLANTILLA DE MLB (ESPN)
 *
 * DIFERENCIA CLAVE con actualizar_plantilla_espn.php (futbol): ahi hacia
 * falta matching de nombre equipo-por-equipo (score >= 0.6) porque no
 * habia vinculo previo confiable para todos los casos. Aca NO hace
 * falta nada de eso -- el puente ESPN->equipo para los 30 equipos de
 * MLB ya existe en external_ids (fuente='espn-beisbol', entidad='equipo'),
 * sembrado por poblar_calendario_mlb_espn.php. Este script simplemente
 * recorre esos 30 vinculos ya confirmados y pide el roster directo por
 * id de ESPN. Si algun equipo de beisbol en la base NO tiene vinculo
 * todavia, se lista al final para revisar a mano (no se intenta
 * adivinar por nombre).
 *
 * SHAPE DEL ROSTER (confirmado en diagnostico de sesion anterior,
 * diagnostico_mlb_espn_resumen.php): a diferencia de futbol, que trae
 * 'athletes' como lista plana de jugadores, beisbol la trae AGRUPADA
 * por posicion:
 *
 *   athletes = [
 *     { position: 'pitchers' (o similar), items: [ {jugador}, {jugador}, ... ] },
 *     { position: 'catchers', items: [...] },
 *     ...
 *   ]
 *
 * Por eso hay un doble foreach (grupo -> items) en vez de un solo
 * foreach como en la version de futbol. Cada item de jugador trae:
 * id, fullName, jersey, position (propia, mas especifica que la del
 * grupo), age, headshot.href, birthPlace.{city,state,country}, bats,
 * throws. bats/throws NO tienen columna en la tabla `jugadores` (ver
 * schema) -- se ignoran por ahora, no se inventan columnas nuevas sin
 * que el usuario lo pida. altura_cm/peso_kg tampoco se completan aca:
 * el shape confirmado no incluye height/weight, y no se debe asumir
 * un campo sin haberlo visto en la respuesta cruda (regla del
 * proyecto). Si en el futuro se confirma que ESPN los trae, se agregan
 * ahi.
 *
 * NACIONALIDAD: se usa birthPlace.country tal cual venga. `jugadores.
 * nacionalidad` es varchar(80) libre, SIN FK a `paises` (verificado
 * contra el dump real) -- a diferencia de estadios/equipos, aca NO
 * hace falta crear "Canada" en ninguna tabla para los jugadores de
 * Toronto, se guarda el texto tal cual. OJO -- en varias APIs de ESPN
 * el campo 'country' viene vacio para jugadores nacidos en USA (el
 * dato "USA" queda implicito, solo se llena country para nacidos
 * afuera). Si eso pasa aca tambien, va a haber muchos jugadores con
 * nacionalidad NULL que en realidad son de Estados Unidos -- se
 * loguea un aviso si mas del 50% de los jugadores de un equipo quedan
 * con nacionalidad NULL, para que se pueda revisar el JSON crudo de
 * un caso puntual y decidir si hace falta el fallback "sin country
 * pero con state -> USA".
 *
 * MATCHING DE JUGADOR CONTRA LA BASE: mismo criterio que la version de
 * futbol -- nombre exacto primero, si no hay, match por apellido (para
 * tolerar variaciones tipo "Jr."/tildes). Jugador de la plantilla
 * fresca que no matchea ninguno existente -> alta nueva. Jugador que
 * esta en la base pero no aparece en la plantilla fresca -> se
 * desactiva (activo=0), no se borra.
 *
 * ESTADISTICA: no viene en este endpoint (es solo roster, no stats de
 * temporada). Se deja estadistica_principal=0 y estadistica_label
 * explicito en 'Carreras' (coincide con deportes.unidad_marcador para
 * beisbol, que guarda 'carreras' en minuscula -- se capitaliza aca
 * igual que el resto de las columnas de deportes ya sembrados usan
 * 'Goles' capitalizado) -- IMPORTANTE ponerlo explicito en el INSERT,
 * porque el default de la columna en la tabla es 'Goles' (piensa en
 * futbol) y dejarlo caer al default estaria mal para beisbol.
 *
 * MODO POR DEFECTO: solo diagnostico (dry-run). Para aplicar de
 * verdad, correr con --aplicar
 *
 * Uso (desde la carpeta seeder/):
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla_mlb_espn.php
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" actualizar_plantilla_mlb_espn.php --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_ESPN_MLB = 'espn-beisbol';

$aplicar = in_array('--aplicar', $argv, true);

// ------------------------------------------------------------------
// Helpers de normalizacion de nombre (mismo criterio que la version
// de futbol, para tolerar tildes / "Jr." / variantes de escritura
// entre corridas).
// ------------------------------------------------------------------

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
        'ñ'=>'n','Ñ'=>'n',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabras(string $nombre): array
{
    $n = sacarAcentos($nombre);
    $n = strtolower($n);
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['jr', 'sr', 'ii', 'iii', 'iv'];
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

// ------------------------------------------------------------------
// Arranque
// ------------------------------------------------------------------

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Correr con --aplicar para ejecutar de verdad ===\n\n";

$idDeporte = $repo->idDeportePorSlug('beisbol');

// Todos los equipos de beisbol activos en la base.
$stmt = $pdo->prepare('SELECT id, nombre, id_liga, activo FROM equipos WHERE id_deporte = ?');
$stmt->execute([$idDeporte]);
$equiposBeisbol = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equiposBeisbol)) {
    echo "No hay equipos de beisbol (id_deporte=$idDeporte) en la base. Algo esta mal.\n";
    exit(1);
}

// Excluye la basura conocida (American League / National League /
// Northeastern / Sugar Land, todos activo=0) -- ninguno tiene vinculo
// espn-beisbol de todos modos, pero se filtra explicito para no ni
// siquiera consultar el vinculo de un equipo que sabemos que es basura.
$equiposActivos = array_values(array_filter($equiposBeisbol, fn($e) => (int)$e['activo'] === 1));

$stmtJugadoresEquipo = $pdo->prepare('SELECT id, nombre, activo FROM jugadores WHERE id_equipo = ?');
$stmtInsertar = $pdo->prepare(
    'INSERT INTO jugadores (id_equipo, nombre, posicion, dorsal, edad, nacionalidad, foto, estadistica_principal, estadistica_label, activo)
     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 1)'
);
$stmtActualizar = $pdo->prepare(
    'UPDATE jugadores SET nombre = ?, posicion = ?, dorsal = ?, edad = ?, nacionalidad = ?, foto = ?, activo = 1 WHERE id = ?'
);
$stmtDesactivar = $pdo->prepare('UPDATE jugadores SET activo = 0 WHERE id = ?');

const ESTADISTICA_LABEL_MLB = 'Carreras';

$sinVinculo = [];
$totalAltas = 0;
$totalBajas = 0;
$totalActualizados = 0;
$totalSinCambio = 0;
$equiposProcesados = 0;

foreach ($equiposActivos as $equipoLocal) {
    $idEspn = $repo->resolverEspnIdDeEquipo(FUENTE_ESPN_MLB, (int)$equipoLocal['id']);

    if ($idEspn === null) {
        // No deberia pasar para los 30 equipos reales de MLB (el
        // puente ya esta sembrado), pero si aparece un equipo nuevo
        // sin vinculo, se lista al final en vez de intentar adivinar
        // por nombre -- mismo criterio defensivo que el resto del
        // seeder.
        $sinVinculo[] = $equipoLocal['nombre'];
        continue;
    }

    $respRoster = $http->get(
        "https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/teams/{$idEspn}/roster",
        []
    );

    if ($respRoster === null || empty($respRoster['athletes'])) {
        echo "  [{$equipoLocal['nombre']}] (ESPN id {$idEspn}) No se pudo obtener el roster desde ESPN (o vino vacio).\n";
        continue;
    }

    $equiposProcesados++;

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
    $totalJugadoresFrescos = 0;
    $sinNacionalidad = 0;

    // Doble foreach: grupo de posicion -> jugadores del grupo. Esto es
    // lo que cambia respecto de la version de futbol (ahi 'athletes'
    // era una lista plana de jugadores).
    foreach ($respRoster['athletes'] as $grupo) {
        $itemsGrupo = $grupo['items'] ?? [];
        $posicionGrupo = $grupo['position'] ?? null;

        foreach ($itemsGrupo as $atleta) {
            $totalJugadoresFrescos++;

            $nombreJugador = $atleta['fullName'] ?? $atleta['displayName'] ?? null;
            if (!$nombreJugador) {
                continue;
            }

            // Posicion propia del jugador si viene, si no cae al
            // nombre del grupo (ej. "Pitchers").
            $posicion = $atleta['position']['displayName']
                ?? $atleta['position']['abbreviation']
                ?? (is_string($posicionGrupo) ? $posicionGrupo : null)
                ?? 'N/D';

            $dorsalCrudo = $atleta['jersey'] ?? null;
            $dorsal = ($dorsalCrudo !== null && $dorsalCrudo !== '') ? (int)$dorsalCrudo : null;

            $edad = $atleta['age'] ?? edadDesdeNacimiento($atleta['dateOfBirth'] ?? null);

            $nacionalidad = $atleta['birthPlace']['country'] ?? null;
            if ($nacionalidad === null) {
                $sinNacionalidad++;
            }

            $foto = $atleta['headshot']['href'] ?? null;

            if (isset($porNombreExacto[$nombreJugador])) {
                $existente = $porNombreExacto[$nombreJugador];
                $vistos[$existente['id']] = true;
                $totalSinCambio++;
                if ($aplicar) {
                    // Aunque el nombre no cambio, actualizamos el resto
                    // (dorsal/edad/nacionalidad/foto pueden variar entre
                    // corridas) -- mismo criterio que hariamos con
                    // upsertGenerico si estuviera usando Repository aca.
                    $stmtActualizar->execute([$nombreJugador, $posicion, $dorsal, $edad, $nacionalidad, $foto, $existente['id']]);
                }
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
                    $stmtActualizar->execute([$nombreJugador, $posicion, $dorsal, $edad, $nacionalidad, $foto, $candidatoJug['id']]);
                }
                $totalActualizados++;
                continue;
            }

            $altas[] = $nombreJugador;
            if ($aplicar) {
                $stmtInsertar->execute([$equipoLocal['id'], $nombreJugador, $posicion, $dorsal, $edad, $nacionalidad, $foto, ESTADISTICA_LABEL_MLB]);
            }
            $totalAltas++;
        }
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

    echo "--- {$equipoLocal['nombre']} (ESPN id {$idEspn}) ---\n";
    echo "  Plantilla fresca: {$totalJugadoresFrescos} jugadores\n";
    if (!empty($altas)) {
        echo "  ALTAS (" . count($altas) . "): " . implode(', ', $altas) . "\n";
    }
    if (!empty($actualizados)) {
        echo "  RENOMBRADOS/match apellido (" . count($actualizados) . "): " . implode(' | ', $actualizados) . "\n";
    }
    if (!empty($bajas)) {
        echo "  BAJAS -> desactivados (" . count($bajas) . "): " . implode(', ', $bajas) . "\n";
    }
    if ($totalJugadoresFrescos > 0 && $sinNacionalidad / $totalJugadoresFrescos > 0.5) {
        echo "  OJO: {$sinNacionalidad}/{$totalJugadoresFrescos} jugadores sin nacionalidad (birthPlace.country vacio) -- revisar si ESPN omite 'country' para nacidos en USA (ver comentario al inicio del script).\n";
    }
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "Equipos procesados: {$equiposProcesados}\n";
echo "Jugadores sin cambio de nombre: {$totalSinCambio}\n";
echo "Jugadores actualizados (match por apellido): {$totalActualizados}\n";
echo "Altas nuevas: {$totalAltas}\n";
echo "Bajas (desactivados): {$totalBajas}\n";

if (!empty($sinVinculo)) {
    echo "\nEquipos de beisbol en la base SIN vinculo espn-beisbol en external_ids (no se proceso su plantilla):\n";
    foreach ($sinVinculo as $n) {
        echo "  - $n\n";
    }
    echo "Esto no deberia pasar para los 30 equipos reales de MLB -- revisar a mano si aparece alguno aca.\n";
}

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}