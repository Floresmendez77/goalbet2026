<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Migracion one-off, acotada a NFL (id_deporte = 4).
 *
 * Mismo problema que resolvio migrar_fuentes_api_sports.php en su
 * momento para Premier League/La Liga/Serie A/NBA/MLB, pero esa
 * corrida NO incluyo NFL (en ese momento tenia muy pocos datos
 * sembrados). Los pocos equipos/liga/partido de NFL que ya existen
 * quedaron con fuente='api-sports' generico (de antes del fix por
 * deporte en ApiSportsSeeder::fuente()), asi que el seeder actual
 * (que busca todo bajo 'api-sports-nfl') no los encuentra e intenta
 * INSERTar de nuevo -> choca con la restriccion unica de ligas_torneos.
 *
 * Este script SOLO actualiza la columna `fuente` en external_ids para
 * las filas cuya entidad de dominio pertenece a id_deporte=4. No borra
 * ni crea nada en ninguna tabla de dominio.
 *
 * Correr UNA vez, desde seeder/, con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" migrar_fuente_nfl.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');
$pdo = Database::connect();

$idDeporteNfl = 4;
$fuenteVieja = 'api-sports';
$fuenteNueva = 'api-sports-nfl';

$queries = [
    'liga' => "
        UPDATE external_ids ei
        JOIN ligas_torneos l ON l.id = ei.entidad_id
        SET ei.fuente = :nueva
        WHERE ei.entidad = 'liga' AND ei.fuente = :vieja AND l.id_deporte = :deporte",
    'equipo' => "
        UPDATE external_ids ei
        JOIN equipos e ON e.id = ei.entidad_id
        SET ei.fuente = :nueva
        WHERE ei.entidad = 'equipo' AND ei.fuente = :vieja AND e.id_deporte = :deporte",
    'jugador' => "
        UPDATE external_ids ei
        JOIN jugadores j ON j.id = ei.entidad_id
        JOIN equipos e ON e.id = j.id_equipo
        SET ei.fuente = :nueva
        WHERE ei.entidad = 'jugador' AND ei.fuente = :vieja AND e.id_deporte = :deporte",
    'partido' => "
        UPDATE external_ids ei
        JOIN partidos p ON p.id = ei.entidad_id
        SET ei.fuente = :nueva
        WHERE ei.entidad = 'partido' AND ei.fuente = :vieja AND p.id_deporte = :deporte",
];

$totalFilas = 0;
foreach ($queries as $entidad => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':nueva' => $fuenteNueva, ':vieja' => $fuenteVieja, ':deporte' => $idDeporteNfl]);
    $filas = $stmt->rowCount();
    $totalFilas += $filas;
    echo "$entidad: $filas filas migradas\n";
}

echo "Total: $totalFilas filas migradas de '$fuenteVieja' a '$fuenteNueva'.\n";
echo "Listo. Ahora se puede volver a correr run_seeder.php para NFL sin choques.\n";