<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * ACTUALIZAR RESULTADOS (ESPN) — trae marcadores reales desde la API
 * publica de ESPN para un rango de fechas y los escribe en los
 * partidos ya sembrados (por cualquier fuente) que correspondan.
 *
 * Que SI hace:
 *   - Por cada dia del rango, pide el scoreboard de ESPN para esa
 *     liga/deporte.
 *   - Matchea cada evento finalizado con el partido correspondiente
 *     en la base (por par de equipos + fecha cercana, o por el
 *     vinculo de una corrida anterior).
 *   - Actualiza marcador_local/marcador_visita/estado -- eso dispara
 *     solo el trigger que liquida las apuestas pendientes.
 *   - Al final, recalcula tabla_posiciones de la liga.
 *
 * Que NO hace:
 *   - No crea equipos nuevos (si no matchea un equipo, omite ese
 *     partido y lo lista para revision manual).
 *   - No crea ligas.
 *   - Por default no crea partidos nuevos tampoco -- pasar
 *     --crear-nuevos si tambien queres completar calendario, no solo
 *     cargar resultados de partidos que ya existian como 'programado'.
 *
 * MODO POR DEFECTO: diagnostico (dry-run), no escribe nada.
 * Para aplicar de verdad: agregar --aplicar
 *
 * Uso:
 *   php actualizar_resultados_espn.php <deporte> <slug_liga_espn> <id_liga> <desde:YYYY-MM-DD> <hasta:YYYY-MM-DD> [--aplicar] [--crear-nuevos]
 *
 * Ejemplos:
 *   # diagnostico del hueco real detectado en Brasileirao (id_liga=9)
 *   php actualizar_resultados_espn.php futbol bra.1 9 2026-07-22 2026-08-09
 *
 *   # aplicar de verdad
 *   php actualizar_resultados_espn.php futbol bra.1 9 2026-07-22 2026-08-09 --aplicar
 *
 *   # otros deportes/ligas (slugs de ESPN, no de tu base):
 *   php actualizar_resultados_espn.php futbol eng.1 1 2026-08-15 2026-08-24 --aplicar   # Premier League
 *   php actualizar_resultados_espn.php futbol esp.1 16 2026-08-15 2026-08-24 --aplicar  # La Liga
 *   php actualizar_resultados_espn.php baloncesto nba 18 2026-08-01 2026-08-07 --aplicar
 *   php actualizar_resultados_espn.php beisbol mlb 19 2023-02-26 2023-03-15 --aplicar
 *   php actualizar_resultados_espn.php futbol-americano nfl 20 2026-07-14 2026-07-16 --aplicar
 *
 * Slugs de liga de ESPN mas comunes (soccer): eng.1 (Premier League),
 * esp.1 (La Liga), ita.1 (Serie A), ger.1 (Bundesliga), fra.1 (Ligue 1),
 * bra.1 (Brasileirao Serie A), arg.1 (Liga Prof. Argentina), usa.1 (MLS),
 * uefa.champions (Champions League). Para otros deportes el slug de
 * liga suele ser el nombre corto: nba, mlb, nfl.
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Seeders/EspnSeeder.php';

Env::load(__DIR__ . '/.env');

$posicionales = array_values(array_filter(
    array_slice($argv, 1),
    fn($a) => !str_starts_with($a, '--')
));
$aplicar = in_array('--aplicar', $argv, true);
$crearNuevos = in_array('--crear-nuevos', $argv, true);

[$deporte, $ligaEspn, $idLiga, $desde, $hasta] = array_pad($posicionales, 5, null);

if (!$deporte || !$ligaEspn || !$idLiga || !$desde || !$hasta) {
    fwrite(STDERR, "Uso: php actualizar_resultados_espn.php <deporte> <slug_liga_espn> <id_liga> <desde:YYYY-MM-DD> <hasta:YYYY-MM-DD> [--aplicar] [--crear-nuevos]\n");
    fwrite(STDERR, "Ejemplo: php actualizar_resultados_espn.php futbol bra.1 9 2026-07-22 2026-08-09 --aplicar\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);
$seeder = new EspnSeeder($repo, $pdo, $http, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

try {
    $seeder->sincronizarResultados(
        $deporte,
        $ligaEspn,
        (int)$idLiga,
        $desde,
        $hasta,
        $aplicar,
        $crearNuevos
    );
} catch (Throwable $e) {
    $logger->error('Fallo fatal: ' . $e->getMessage());
}

$logger->resumen();