<?php

/**
 * reparar_colision_pl_mlb.php
 *
 * Segunda vuelta del problema de colision de external_id entre deportes.
 *
 * diagnostico_colision.php ya habia detectado que los external_id 33-36 de
 * 'api-sports' colisionaban entre futbol (Man Utd, Newcastle, Bournemouth,
 * Fulham) y beisbol (Cardinals, Rays, Rangers, Blue Jays). reparar_equipos_mlb.php
 * agrego los 4 equipos de beisbol correctos (ids 225-228, fuente
 * 'api-sports-baseball') pero NUNCA toco los 4 equipos de futbol que
 * quedaron con el escudo y el id_liga de beisbol pisados encima -- y que
 * ademas quedaron usados por error en cientos de partidos de la temporada
 * de MLB.
 *
 * Este script:
 *  1. Reasigna todos los partidos de beisbol (id_deporte=3, id_liga=19)
 *     que usan los equipos 97/98/99/100 -> los equipos correctos 225-228.
 *  2. Restaura los 4 equipos de futbol: id_liga=15 (Premier League
 *     api-sports 2023), escudo correcto del club real.
 *  3. Limpia el external_id incorrecto que quedo apuntando esos 4 equipos
 *     de futbol a numeros de beisbol.
 *  4. Recalcula tabla_posiciones para liga 19 (MLB) y liga 15 (PL 2023).
 *
 * Uso: php reparar_colision_pl_mlb.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}
require_once __DIR__ . '/../seeder/Env.php';
require_once __DIR__ . '/../seeder/Logger.php';
require_once __DIR__ . '/../seeder/Repository.php';

const ID_DEPORTE_BEISBOL = 3;
const ID_LIGA_MLB        = 19;
const ID_LIGA_PL_2023    = 15;

// equipo_futbol_corrupto => [equipo_beisbol_correcto, nombre_real, escudo_correcto]
const MAPA_CORRECCION = [
    97  => [225, 'Manchester United FC', 'https://crests.football-data.org/66.png'],
    98  => [226, 'Newcastle United FC',  'https://crests.football-data.org/67.png'],
    99  => [227, 'AFC Bournemouth',      'https://crests.football-data.org/bournemouth.png'],
    100 => [228, 'Fulham FC',            'https://crests.football-data.org/63.png'],
];

try {
    $pdo = db();
    $logger = new Logger();
    $repo = new Repository($pdo, $logger);

    $stmtUpdateLocal  = $pdo->prepare(
        "UPDATE partidos SET id_equipo_local = :nuevo
         WHERE id_deporte = :dep AND id_liga = :liga AND id_equipo_local = :viejo"
    );
    $stmtUpdateVisita = $pdo->prepare(
        "UPDATE partidos SET id_equipo_visita = :nuevo
         WHERE id_deporte = :dep AND id_liga = :liga AND id_equipo_visita = :viejo"
    );
    $stmtRestaurarEquipo = $pdo->prepare(
        "UPDATE equipos SET id_liga = :liga, escudo = :escudo, nombre = :nombre
         WHERE id = :id"
    );
    $stmtBorrarExternalId = $pdo->prepare(
        "DELETE FROM external_ids WHERE entidad = 'equipo' AND entidad_id = :id AND fuente = 'api-sports'"
    );

    echo "== Reasignando partidos de beisbol a los equipos correctos ==\n";
    $totalReasignados = 0;
    foreach (MAPA_CORRECCION as $viejo => [$nuevo, $nombreReal, $escudoReal]) {
        $pdo->beginTransaction();

        $stmtUpdateLocal->execute([':nuevo' => $nuevo, ':dep' => ID_DEPORTE_BEISBOL, ':liga' => ID_LIGA_MLB, ':viejo' => $viejo]);
        $localesReasignados = $stmtUpdateLocal->rowCount();

        $stmtUpdateVisita->execute([':nuevo' => $nuevo, ':dep' => ID_DEPORTE_BEISBOL, ':liga' => ID_LIGA_MLB, ':viejo' => $viejo]);
        $visitasReasignadas = $stmtUpdateVisita->rowCount();

        $stmtRestaurarEquipo->execute([
            ':liga'   => ID_LIGA_PL_2023,
            ':escudo' => $escudoReal,
            ':nombre' => $nombreReal,
            ':id'     => $viejo,
        ]);

        $stmtBorrarExternalId->execute([':id' => $viejo]);

        $pdo->commit();

        $sub = $localesReasignados + $visitasReasignadas;
        $totalReasignados += $sub;
        echo "✔ equipo $viejo ('$nombreReal'): $sub partidos reasignados a id $nuevo | equipo restaurado (liga=15, escudo real)\n";
    }

    echo "\n== Recalculando tablas de posiciones ==\n";
    $repo->recalcularTablaPosiciones(ID_LIGA_MLB, '2023');
    echo "✔ tabla_posiciones recalculada para liga 19 (MLB)\n";
    $repo->recalcularTablaPosiciones(ID_LIGA_PL_2023, '2023');
    echo "✔ tabla_posiciones recalculada para liga 15 (Premier League api-sports 2023)\n";

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "RESUMEN\n";
    echo "  Partidos de MLB reasignados: $totalReasignados\n";
    echo "  Equipos de futbol restaurados: " . count(MAPA_CORRECCION) . "\n";
    echo "\nVerificacion sugerida:\n";
    echo "  SELECT id, nombre, id_liga, escudo FROM equipos WHERE id IN (97,98,99,100);\n";
    echo "  SELECT COUNT(*) FROM partidos WHERE id_deporte=3 AND id_liga=19 AND (id_equipo_local IN (97,98,99,100) OR id_equipo_visita IN (97,98,99,100));\n";
    echo "  -- deberia devolver 0\n";
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "✘ Error de base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}