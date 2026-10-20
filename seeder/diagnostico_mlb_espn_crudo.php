<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * DIAGNOSTICO CRUDO ESPN PARA MLB -- confirma el shape de los 4
 * endpoints que hacen falta para traer la temporada 2026 completa
 * (partidos, equipos, estadios, plantillas) antes de escribir
 * cualquier script real, siguiendo la regla del proyecto.
 *
 * POR QUE ESPN Y NO api-sports: diagnostico_mlb_crudo.php confirmo
 * que api-sports para beisbol (a) tiene tope de temporada 2022-2024 en
 * el plan gratis -- no da la 2026 -- y (b) NO tiene endpoints de
 * venues ni de players en absoluto (confirmado contra la respuesta
 * real Y contra la lista oficial de endpoints de la documentacion:
 * Timezone, Seasons, Countries, Leagues, Teams, Standings, Games,
 * Odds -- eso es TODO lo que ofrece ese dominio). ESPN ya se usa en
 * el proyecto para futbol con exito (ver poblar_calendario_espn.php,
 * actualizar_plantilla_espn.php) y su familia de APIs SI cubre
 * venues y rosters para baseball.
 *
 * Slug de liga: se usa 'mlb' (confirmado en un log historico del
 * proyecto -- seed_2026-08-13.log referencia 'liga_espn=mlb' -- y es
 * el slug estandar de ESPN para las Grandes Ligas).
 *
 * Este script SOLO lee, no escribe nada en la base. Dumpea:
 *   A) /scoreboard?dates=<hoy> -- shape de un partido (fecha, equipos,
 *      marcador, status) y si trae partidos SCHEDULED (futuros) o solo
 *      pasados.
 *   B) /teams?limit=50 -- shape de la lista de equipos (para
 *      confirmar id, nombre, logo).
 *   C) /teams/<id> (detalle de UN equipo) -- para ver si aca aparece
 *      el estadio (venue) anidado, ya que la lista de /teams a veces
 *      viene resumida y el detalle trae mas campos.
 *   D) /teams/<id>/roster -- shape de la plantilla de jugadores.
 *
 * Uso:
 *   php diagnostico_mlb_espn_crudo.php [fecha AAAA-MM-DD = hoy]
 *
 * Ejemplos:
 *   php diagnostico_mlb_espn_crudo.php
 *   php diagnostico_mlb_espn_crudo.php 2026-08-15
 */

require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

$fecha = $argv[1] ?? date('Y-m-d');
$fechaCompacta = str_replace('-', '', $fecha);

$logger = new Logger();
$http = new HttpClient($logger);

$base = 'https://site.api.espn.com/apis/site/v2/sports/baseball/mlb';

echo "========================================================\n";
echo "PRUEBA A: /scoreboard?dates=$fechaCompacta -- shape de un partido y si trae futuros\n";
echo "========================================================\n";
$respScoreboard = $http->get("$base/scoreboard?dates=$fechaCompacta", []);
if ($respScoreboard === null) {
    echo "FALLO: no se pudo obtener respuesta de /scoreboard.\n";
} else {
    $eventos = $respScoreboard['events'] ?? [];
    echo "Total eventos para $fecha: " . count($eventos) . "\n";
    $porStatus = [];
    foreach ($eventos as $ev) {
        $status = $ev['status']['type']['name'] ?? $ev['competitions'][0]['status']['type']['name'] ?? '(sin status)';
        $porStatus[$status] = ($porStatus[$status] ?? 0) + 1;
    }
    echo "Por status: " . json_encode($porStatus) . "\n";
    if (!empty($eventos)) {
        echo "\n--- Evento[0] CRUDO completo ---\n";
        echo json_encode($eventos[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "\n0 eventos hoy -- probando +7 dias para encontrar al menos un partido programado...\n";
        $cursor = new DateTime($fecha);
        for ($i = 1; $i <= 7; $i++) {
            $cursor->modify('+1 day');
            $fc = $cursor->format('Ymd');
            $r = $http->get("$base/scoreboard?dates=$fc", []);
            $ev = $r['events'] ?? [];
            if (!empty($ev)) {
                echo "Encontrados " . count($ev) . " eventos en {$cursor->format('Y-m-d')}\n";
                echo "\n--- Evento[0] CRUDO completo (de esa fecha) ---\n";
                echo json_encode($ev[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                break;
            }
        }
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA B: /teams?limit=50 -- shape de la lista de equipos\n";
echo "========================================================\n";
$respTeams = $http->get("$base/teams?limit=50", []);
$primerIdEquipo = null;
if ($respTeams === null) {
    echo "FALLO: no se pudo obtener respuesta de /teams.\n";
} else {
    // ESPN anida la lista bajo sports[0].leagues[0].teams, cada item con 'team'
    $listaEquipos = $respTeams['sports'][0]['leagues'][0]['teams'] ?? [];
    echo "Total equipos recibidos: " . count($listaEquipos) . "\n";
    if (!empty($listaEquipos)) {
        $primerEquipo = $listaEquipos[0]['team'] ?? $listaEquipos[0];
        $primerIdEquipo = $primerEquipo['id'] ?? null;
        echo "\n--- Equipo[0] CRUDO completo (buscar: 'venue' anidado aca mismo) ---\n";
        echo json_encode($primerEquipo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "0 equipos -- respuesta cruda completa (primeros 500 chars):\n";
        echo substr(json_encode($respTeams), 0, 500) . "\n";
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA C: /teams/<id> -- detalle de UN equipo (buscar venue si no vino en PRUEBA B)\n";
echo "========================================================\n";
if ($primerIdEquipo === null) {
    echo "No hay id de equipo para probar (PRUEBA B no trajo ninguno).\n";
} else {
    $respDetalle = $http->get("$base/teams/$primerIdEquipo", []);
    if ($respDetalle === null) {
        echo "FALLO: no se pudo obtener respuesta de /teams/$primerIdEquipo.\n";
    } else {
        echo "--- Detalle CRUDO completo del equipo id=$primerIdEquipo ---\n";
        echo json_encode($respDetalle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA D: /teams/<id>/roster -- shape de la plantilla\n";
echo "========================================================\n";
if ($primerIdEquipo === null) {
    echo "No hay id de equipo para probar.\n";
} else {
    $respRoster = $http->get("$base/teams/$primerIdEquipo/roster", []);
    if ($respRoster === null) {
        echo "FALLO: no se pudo obtener respuesta de /teams/$primerIdEquipo/roster.\n";
    } else {
        $atletas = $respRoster['athletes'] ?? [];
        echo "Total jugadores/grupos recibidos en 'athletes': " . count($atletas) . "\n";
        echo "\n--- Primeros 400 caracteres de 'athletes' CRUDO (puede venir agrupado por posicion) ---\n";
        echo substr(json_encode($atletas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0, 1500) . "\n";
    }
}

echo "\n\n========================================================\n";
echo "CONCLUSIONES A CHEQUEAR A MANO\n";
echo "========================================================\n";
echo "1. PRUEBA A: si hoy ($fecha) o algun dia de los +7 probados trae\n";
echo "   status SCHEDULED, confirma que el scoreboard SI expone partidos\n";
echo "   futuros para MLB (esperable, porque la temporada 2026 esta en\n";
echo "   curso en agosto -- confirmar igual, no asumir).\n";
echo "2. PRUEBA B y C: ver en cual de las dos aparece 'venue' (nombre,\n";
echo "   ciudad, capacidad) -- eso decide si alcanza con /teams o hace\n";
echo "   falta pegar a /teams/<id> por separado para cada equipo (32\n";
echo "   requests extra, aceptable dado que son pocos equipos).\n";
echo "3. PRUEBA D: confirmar el shape exacto de cada jugador dentro de\n";
echo "   'athletes' (nombre, posicion, dorsal, foto, nacionalidad) --\n";
echo "   ESPN a veces agrupa el roster por posicion (arrays anidados)\n";
echo "   en vez de una lista plana, hay que confirmar cual de las dos\n";
echo "   formas usa para baseball especificamente.\n";