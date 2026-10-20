<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * DIAGNOSTICO CRUDO MLB (v1.baseball.api-sports.io) -- confirma contra
 * la realidad antes de escribir o correr cualquier script de siembra
 * nueva para MLB, siguiendo la regla del proyecto de nunca asumir el
 * shape de un endpoint sin volcar el JSON crudo primero.
 *
 * POR QUE ESTE DIAGNOSTICO PUNTUAL (no es "shape totalmente
 * desconocido" como se penso al principio): revisando los logs
 * historicos del proyecto (seed_2026-07-13.log) se confirmo que MLB
 * YA fue sembrado parcialmente en el pasado via ApiSportsSeeder:
 *   - Liga MLB (id_liga_bd=19, api-sports league id=1) ya existe.
 *   - 30 equipos reales ya estan sembrados (falta id_pais/id_estadio).
 *   - 2940 partidos de la TEMPORADA 2023 ya estan en la base (fuente
 *     'api-sports-baseball'), rango 2023-02-24 a 2023-11-02.
 *   - Los jugadores fallaron: /players?team=X&season=2023 empezo a
 *     tirar timeouts y "connection reset" a partir mas o menos del
 *     equipo #30 en adelante (ver seed_2026-07-13.log lineas 7607+).
 *     No se sabe si fue un problema de red puntual de esa corrida o
 *     una restriccion real del plan gratis (como ya paso con /players
 *     en futbol).
 *
 * Como estamos en agosto 2026 y los partidos sembrados son de la
 * temporada 2023 (historicos, inutiles para apostar en partidos
 * reales), el gap real no es "sembrar MLB desde cero" sino:
 *   A) Confirmar si el plan da acceso a la temporada 2026 (o cual es
 *      la temporada mas reciente disponible) -- /leagues trae un
 *      array 'seasons' con 'coverage' por temporada, que en teoria
 *      deberia decir que datos estan habilitados (fixtures, players,
 *      etc.) sin tener que ir a probar a ciegas contra cada endpoint.
 *   B) Confirmar el shape de /teams -- especificamente si trae el
 *      estadio (venue) anidado como en futbol, o si beisbol no tiene
 *      ese campo y hay que resolver estadios por otra via.
 *   C) Reintentar /players contra UN equipo puntual para ver si el
 *      fallo de la vez pasada se repite (asi sabemos si es un limite
 *      de plan real o fue un problema de red transitorio).
 *
 * MODO: 100% lectura, no escribe nada en la base. Solo imprime JSON
 * crudo para revisar a mano.
 *
 * Uso:
 *   php diagnostico_mlb_crudo.php [temporada=2026] [id_equipo_prueba]
 *
 * Ejemplos:
 *   php diagnostico_mlb_crudo.php
 *   php diagnostico_mlb_crudo.php 2025
 *   php diagnostico_mlb_crudo.php 2026 199
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

Env::load(__DIR__ . '/.env');

$temporada = $argv[1] ?? '2026';
$idEquipoPrueba = $argv[2] ?? null; // si no se pasa, se usa el primer equipo que traiga /teams

$apiKey = Env::get('API_SPORTS_KEY', '');
if ($apiKey === '') {
    fwrite(STDERR, "No se encontro API_SPORTS_KEY en .env -- revisa seeder/.env\n");
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);
$baseUrl = 'https://v1.baseball.api-sports.io';
$headers = ["x-apisports-key: $apiKey"];

const ID_LIGA_MLB_API = 1; // confirmado: liga MLB en api-sports = id 1, ya sembrada como id_liga_bd=19

echo "========================================================\n";
echo "PRUEBA A: /leagues?id=" . ID_LIGA_MLB_API . " -- coverage por temporada\n";
echo "========================================================\n";
$respLigas = $http->get("$baseUrl/leagues?id=" . ID_LIGA_MLB_API, $headers);
if ($respLigas === null) {
    echo "FALLO: no se pudo obtener respuesta de /leagues (revisa el log de HttpClient arriba).\n";
} elseif (!empty($respLigas['errors'])) {
    echo "La API devolvio errores: " . json_encode($respLigas['errors']) . "\n";
} else {
    $ligaInfo = $respLigas['response'][0] ?? null;
    if ($ligaInfo === null) {
        echo "Respuesta vacia -- JSON crudo completo:\n";
        echo json_encode($respLigas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Nombre: " . ($ligaInfo['name'] ?? '?') . "\n";
        echo "Pais: " . json_encode($ligaInfo['country'] ?? null) . "\n";
        echo "\nTemporadas y coverage (buscar la fila de temporada=$temporada):\n";
        foreach ($ligaInfo['seasons'] ?? [] as $s) {
            $season = $s['season'] ?? '?';
            $marca = ((string)$season === (string)$temporada) ? '  <<<< TEMPORADA PEDIDA' : '';
            echo "  temporada $season | coverage: " . json_encode($s['coverage'] ?? null) . "$marca\n";
        }
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA B: /teams?league=" . ID_LIGA_MLB_API . "&season=$temporada -- shape completo\n";
echo "========================================================\n";
$respEquipos = $http->get("$baseUrl/teams?league=" . ID_LIGA_MLB_API . "&season=$temporada", $headers);
$primerEquipoId = null;
if ($respEquipos === null) {
    echo "FALLO: no se pudo obtener respuesta de /teams.\n";
} elseif (!empty($respEquipos['errors'])) {
    echo "La API devolvio errores: " . json_encode($respEquipos['errors']) . "\n";
    echo "(Si dice algo como 'Free plans do not have access to this season', confirma que hay\n";
    echo " que usar una temporada anterior -- revisar el coverage de la PRUEBA A para saber cual.)\n";
} else {
    $equipos = $respEquipos['response'] ?? [];
    echo "Total equipos recibidos: " . count($equipos) . "\n";
    if (!empty($equipos)) {
        $primerEquipoId = $equipos[0]['id'] ?? $equipos[0]['team']['id'] ?? null;
        echo "\n--- Equipo[0] CRUDO completo (buscar: 'venue'/estadio anidado, 'country' por equipo) ---\n";
        echo json_encode($equipos[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "0 equipos -- respuesta cruda completa:\n";
        echo json_encode($respEquipos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA C: /games?league=" . ID_LIGA_MLB_API . "&season=$temporada -- confirmar que hay partidos para esta temporada\n";
echo "========================================================\n";
$respJuegos = $http->get("$baseUrl/games?league=" . ID_LIGA_MLB_API . "&season=$temporada", $headers);
if ($respJuegos === null) {
    echo "FALLO: no se pudo obtener respuesta de /games.\n";
} elseif (!empty($respJuegos['errors'])) {
    echo "La API devolvio errores: " . json_encode($respJuegos['errors']) . "\n";
} else {
    $juegos = $respJuegos['response'] ?? [];
    echo "Total partidos recibidos para temporada $temporada: " . count($juegos) . "\n";
    if (!empty($juegos)) {
        echo "\n--- Partido[0] CRUDO completo ---\n";
        echo json_encode($juegos[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n\n========================================================\n";
echo "PRUEBA D: /players?team=X&season=$temporada -- reintentar el endpoint que fallo la vez pasada\n";
echo "========================================================\n";
$idParaProbar = $idEquipoPrueba ?? $primerEquipoId;
if ($idParaProbar === null) {
    echo "No hay ningun id de equipo para probar (ni se paso por argumento, ni /teams trajo alguno en PRUEBA B).\n";
} else {
    echo "Probando contra equipo id=$idParaProbar ...\n";
    $respJugadores = $http->get("$baseUrl/players?team=$idParaProbar&season=$temporada", $headers);
    if ($respJugadores === null) {
        echo "SIGUE FALLANDO: no se pudo obtener respuesta (mismo tipo de error que la vez pasada,\n";
        echo "revisa el log de HttpClient arriba para el motivo exacto -- timeout, conexion reseteada, etc).\n";
        echo "Si vuelve a fallar en una segunda corrida de este mismo diagnostico, es mas probable que\n";
        echo "sea una restriccion real del plan (no un problema de red transitorio).\n";
    } elseif (!empty($respJugadores['errors'])) {
        echo "La API SI respondio, pero con errores: " . json_encode($respJugadores['errors']) . "\n";
        echo "(esto confirma que es una restriccion de PLAN, no un problema de red -- el mensaje de\n";
        echo "'errors' suele decir explicitamente que no tenes acceso a este endpoint/temporada.)\n";
    } else {
        $jugadores = $respJugadores['response'] ?? [];
        echo "EXITO -- total jugadores recibidos: " . count($jugadores) . "\n";
        if (!empty($jugadores)) {
            echo "\n--- Jugador[0] CRUDO completo ---\n";
            echo json_encode($jugadores[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "0 jugadores pero sin error -- respuesta cruda completa:\n";
            echo json_encode($respJugadores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

echo "\n\n========================================================\n";
echo "CONCLUSIONES A CHEQUEAR A MANO\n";
echo "========================================================\n";
echo "1. En PRUEBA A, ver si la temporada $temporada tiene coverage.fixtures=true\n";
echo "   y coverage.players=true (o el nombre que use este dominio para esas\n";
echo "   flags). Si $temporada no esta en la lista o coverage=false, hay que\n";
echo "   usar la temporada mas reciente que SI tenga coverage completo.\n";
echo "2. En PRUEBA B, confirmar si el equipo trae 'venue' anidado (estadio) y/o\n";
echo "   'country' -- eso decide si se puede reusar el patron de\n";
echo "   poblar_estadios_liga_apisports.php o si hace falta un script distinto\n";
echo "   para resolver estadios/paises de MLB.\n";
echo "3. En PRUEBA D, si vuelve a fallar del mismo modo (timeout/reset) es\n";
echo "   mas probable que sea un problema de red de tu conexion/MAMP en ese\n";
echo "   momento puntual -- si en cambio la API responde con un error\n";
echo "   explicito de plan, confirma que /players para beisbol no esta\n";
echo "   disponible en el plan gratis y hay que buscar otra fuente (ESPN,\n";
echo "   como ya se hizo para futbol) para las plantillas de jugadores.\n";