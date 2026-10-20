<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * DIAGNOSTICO ESPN MLB -- version RESUMIDA de diagnostico_mlb_espn_crudo.php.
 *
 * Ese script volcaba el JSON completo de cada endpoint (utilísimo para
 * guardarlo en un archivo y revisarlo con calma, pero demasiado largo
 * para pegar en un chat -- salieron ~4000 lineas). Este script pega
 * contra los mismos 4 endpoints pero SOLO imprime los campos puntuales
 * que hacen falta para decidir como escribir el script real: si hay
 * partidos futuros, si 'venue' esta en la lista de equipos o en el
 * detalle, y el shape resumido de un jugador del roster.
 *
 * Uso:
 *   php diagnostico_mlb_espn_resumen.php [fecha AAAA-MM-DD = hoy]
 */

require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

$fecha = $argv[1] ?? date('Y-m-d');
$fechaCompacta = str_replace('-', '', $fecha);

$logger = new Logger();
$http = new HttpClient($logger);
$base = 'https://site.api.espn.com/apis/site/v2/sports/baseball/mlb';

echo "=== A) SCOREBOARD ===\n";
$fechaUsada = $fecha;
$eventos = [];
$cursor = new DateTime($fecha);
for ($i = 0; $i <= 7; $i++) {
    $fc = $cursor->format('Ymd');
    $r = $http->get("$base/scoreboard?dates=$fc", []);
    $ev = $r['events'] ?? [];
    if (!empty($ev)) {
        $eventos = $ev;
        $fechaUsada = $cursor->format('Y-m-d');
        break;
    }
    $cursor->modify('+1 day');
}
echo "Fecha con partidos encontrada: $fechaUsada\n";
echo "Total eventos: " . count($eventos) . "\n";
$statusVistos = [];
foreach ($eventos as $ev) {
    $st = $ev['status']['type']['name'] ?? '?';
    $statusVistos[$st] = ($statusVistos[$st] ?? 0) + 1;
}
echo "Status encontrados: " . json_encode($statusVistos) . "\n";
if (!empty($eventos)) {
    $ev = $eventos[0];
    $comp = $ev['competitions'][0] ?? [];
    $competidores = $comp['competitors'] ?? [];
    echo "\nEvento[0] resumen:\n";
    echo "  id: " . ($ev['id'] ?? '?') . "\n";
    echo "  fecha: " . ($ev['date'] ?? '?') . "\n";
    echo "  status.type.name: " . ($ev['status']['type']['name'] ?? $comp['status']['type']['name'] ?? '?') . "\n";
    foreach ($competidores as $c) {
        echo "  competitor: homeAway=" . ($c['homeAway'] ?? '?')
            . " | team.id=" . ($c['team']['id'] ?? '?')
            . " | team.displayName=" . ($c['team']['displayName'] ?? '?')
            . " | score=" . ($c['score'] ?? '?') . "\n";
    }
    echo "  venue en el evento: " . (isset($comp['venue']) ? json_encode($comp['venue']) : '(no presente)') . "\n";
}

echo "\n\n=== B) TEAMS (lista) ===\n";
$respTeams = $http->get("$base/teams?limit=50", []);
$listaEquipos = $respTeams['sports'][0]['leagues'][0]['teams'] ?? [];
echo "Total equipos: " . count($listaEquipos) . "\n";
$primerIdEquipo = null;
if (!empty($listaEquipos)) {
    $t = $listaEquipos[0]['team'] ?? $listaEquipos[0];
    $primerIdEquipo = $t['id'] ?? null;
    echo "Equipo[0] resumen:\n";
    echo "  id: " . ($t['id'] ?? '?') . "\n";
    echo "  displayName: " . ($t['displayName'] ?? '?') . "\n";
    echo "  location: " . ($t['location'] ?? '?') . "\n";
    echo "  claves top-level disponibles: " . implode(', ', array_keys($t)) . "\n";
    echo "  venue en la lista: " . (isset($t['venue']) ? json_encode($t['venue']) : '(no presente en la lista)') . "\n";
}

echo "\n\n=== C) TEAM DETALLE (/teams/<id>) ===\n";
if ($primerIdEquipo !== null) {
    $respDetalle = $http->get("$base/teams/$primerIdEquipo", []);
    $team = $respDetalle['team'] ?? $respDetalle;
    echo "claves top-level disponibles en detalle: " . implode(', ', array_keys($team)) . "\n";
    echo "venue en el detalle: " . (isset($team['venue']) ? json_encode($team['venue'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '(no presente en el detalle tampoco)') . "\n";
    echo "country/location en detalle: " . json_encode($team['location'] ?? $team['country'] ?? '(no encontrado)') . "\n";
} else {
    echo "No hay id de equipo para probar.\n";
}

echo "\n\n=== D) ROSTER (/teams/<id>/roster) ===\n";
if ($primerIdEquipo !== null) {
    $respRoster = $http->get("$base/teams/$primerIdEquipo/roster", []);
    $atletas = $respRoster['athletes'] ?? [];
    echo "Tipo de 'athletes': " . (array_is_list($atletas) ? 'lista plana' : 'objeto/agrupado') . "\n";
    echo "Cantidad de entradas en el primer nivel: " . count($atletas) . "\n";

    // Puede venir agrupado por posicion (cada entrada tiene 'items') o
    // plano (cada entrada YA es un jugador). Se detecta y se muestra
    // el primer jugador real de cualquiera de las dos formas.
    $primerJugador = null;
    if (!empty($atletas)) {
        if (isset($atletas[0]['items'])) {
            echo "Formato: AGRUPADO por posicion (cada grupo tiene 'position' + 'items')\n";
            echo "Grupos encontrados: ";
            foreach ($atletas as $grupo) {
                echo ($grupo['position'] ?? '?') . "(" . count($grupo['items'] ?? []) . ") ";
            }
            echo "\n";
            $primerJugador = $atletas[0]['items'][0] ?? null;
        } else {
            echo "Formato: LISTA PLANA (cada entrada ya es un jugador)\n";
            $primerJugador = $atletas[0];
        }
    }

    if ($primerJugador !== null) {
        echo "\nJugador[0] resumen:\n";
        echo "  id: " . ($primerJugador['id'] ?? '?') . "\n";
        echo "  fullName: " . ($primerJugador['fullName'] ?? '?') . "\n";
        echo "  jersey (dorsal): " . ($primerJugador['jersey'] ?? '?') . "\n";
        echo "  position: " . json_encode($primerJugador['position'] ?? '?') . "\n";
        echo "  age: " . ($primerJugador['age'] ?? '?') . "\n";
        echo "  headshot: " . json_encode($primerJugador['headshot'] ?? '?') . "\n";
        echo "  claves top-level disponibles: " . implode(', ', array_keys($primerJugador)) . "\n";
    } else {
        echo "No se pudo extraer ningun jugador -- respuesta cruda primeros 400 chars:\n";
        echo substr(json_encode($respRoster), 0, 400) . "\n";
    }
} else {
    echo "No hay id de equipo para probar.\n";
}

echo "\n\nFIN DEL RESUMEN.\n";