<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * MIGRACION LIVERPOOL + TOTTENHAM — GAP-003, continuacion de
 * migrar_partidos_premier.php.
 *
 * Contexto (verificado con SELECT reales sobre el dump, no supuesto):
 * a diferencia de los 12 equipos que ya migro migrar_partidos_premier.php
 * (donde el id bajo/duplicado no tenia jugadores ni historial), en estos
 * dos casos el id bajo (liga_futuro = 1) SI tiene partidos programados de
 * la temporada 2026-27 -- incluso con apuestas reales asociadas (todas ya
 * en estado 'cancelada', asi que no hay dinero pendiente en riesgo) -- y
 * el id alto/historico (liga = 15) tiene el historial 2023-24 finalizado,
 * external_ids completos (football-data + api-sports-futbol) y el roster.
 *
 * Direccion confirmada equipo por equipo (conteos reales, no el patron
 * generico de los 12 anteriores):
 *   - Liverpool:  id=6  (38 partidos liga=1, 32 jugadores)
 *              -> id=102 (50 partidos liga=15+CL, 47 jugadores)
 *     Se comparo apellido por apellido: los 32 de id=6 estan TODOS
 *     contenidos en los 47 de id=102 (0 exclusivos de id=6) -> es la
 *     misma plantilla, id=102 mas completa/actualizada. Por eso este
 *     script, a proposito, NO borra los jugadores de id=6: los deja
 *     listados para que un humano confirme el duplicado exacto y decida
 *     si borrarlos, en vez de asumirlo aca.
 *   - Tottenham:  id=11 (38 partidos liga=1, 0 jugadores)
 *              -> id=106 (48 partidos liga=15+CL, 53 jugadores)
 *     Sin jugadores en el lado viejo, no hay nada que revisar a mano.
 *
 * Chelsea (3 -> 108) queda FUERA de este mapeo a proposito: solo 25 de
 * 46 apellidos coinciden entre ambos lados (14 exclusivos de un lado, 21
 * del otro) -- no es un duplicado limpio de la misma plantilla, necesita
 * revision manual antes de decidir el sentido del merge.
 *
 * Este script:
 *   1. Migra partidos.id_equipo_local / id_equipo_visita en la liga 1
 *      (equipos "futuro" 2026-27) para que apunten al equipo correcto.
 *      Las apuestas asociadas no se tocan: referencian id_partido, no
 *      id_equipo, y sobreviven solas al repunte.
 *   2. Recalcula tabla_posiciones para la liga 1.
 *   3. Verifica referencias colgantes (partidos, jugadores,
 *      estadisticas_jugador_partido, estadisticas_jugador_temporada,
 *      tabla_posiciones) antes de borrar cualquier equipo duplicado.
 *      Si un equipo todavia tiene jugadores (caso Liverpool/id=6 por
 *      diseno), NO se borra -- se deja listado para revision manual.
 *
 * Seguro de correr mas de una vez (mismo comportamiento idempotente que
 * migrar_partidos_premier.php).
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" migrar_liverpool_tottenham.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

const ID_LIGA_FUTURO = 1;

// Mapeo confirmado a mano (conteos + comparacion de apellidos, ver arriba):
// id_equipo_duplicado (liga_futuro=1) => id_equipo_historico_correcto (liga=15)
$mapeo = [
    6  => 102, // Liverpool
    11 => 106, // Tottenham
    // 3 => 108 (Chelsea) queda fuera a proposito, ver comentario arriba.
];

$pdo = Database::connect();
$logger = new Logger();

echo "=== PASO 1: Verificacion previa ===\n";
$stmtCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM partidos WHERE id_liga = ? AND (id_equipo_local = ? OR id_equipo_visita = ?)'
);
$totalAntes = 0;
foreach ($mapeo as $idViejo => $idNuevo) {
    $stmtCheck->execute([ID_LIGA_FUTURO, $idViejo, $idViejo]);
    $cant = (int)$stmtCheck->fetchColumn();
    $totalAntes += $cant;
    echo "  Equipo duplicado #{$idViejo} -> aparece en {$cant} partidos de la liga " . ID_LIGA_FUTURO . "\n";
}
echo "Total de referencias a migrar: {$totalAntes}\n\n";

if ($totalAntes === 0) {
    echo "No hay nada para migrar (ya se corrio antes, o no hay partidos con estos ids). Se continua igual para verificar limpieza de equipos.\n\n";
}

echo "=== PASO 2: Migrando partidos.id_equipo_local / id_equipo_visita ===\n";
$pdo->beginTransaction();
$actualizados = 0;
try {
    $stmtLocal = $pdo->prepare(
        'UPDATE partidos SET id_equipo_local = ? WHERE id_liga = ? AND id_equipo_local = ?'
    );
    $stmtVisita = $pdo->prepare(
        'UPDATE partidos SET id_equipo_visita = ? WHERE id_liga = ? AND id_equipo_visita = ?'
    );

    foreach ($mapeo as $idViejo => $idNuevo) {
        $stmtLocal->execute([$idNuevo, ID_LIGA_FUTURO, $idViejo]);
        $actualizados += $stmtLocal->rowCount();
        $stmtVisita->execute([$idNuevo, ID_LIGA_FUTURO, $idViejo]);
        $actualizados += $stmtVisita->rowCount();
        echo "  #{$idViejo} -> #{$idNuevo}: " . ($stmtLocal->rowCount() + $stmtVisita->rowCount()) . " filas actualizadas\n";
    }

    $pdo->commit();
    echo "Total de filas de partidos actualizadas: {$actualizados}\n\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR durante la migracion, se revirtio todo: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== PASO 3: Verificacion posterior ===\n";
$totalDespues = 0;
foreach ($mapeo as $idViejo => $idNuevo) {
    $stmtCheck->execute([ID_LIGA_FUTURO, $idViejo, $idViejo]);
    $cant = (int)$stmtCheck->fetchColumn();
    $totalDespues += $cant;
    if ($cant > 0) {
        echo "  ATENCION: equipo duplicado #{$idViejo} todavia aparece en {$cant} partidos de la liga " . ID_LIGA_FUTURO . " (no deberia)\n";
    }
}
if ($totalDespues === 0) {
    echo "  OK: ningun partido de la liga " . ID_LIGA_FUTURO . " referencia ya a los equipos duplicados.\n";
}
echo "\n";

echo "=== PASO 3b: Verificacion de apuestas (no se tocan, solo se confirma que sobreviven) ===\n";
$stmtApuestas = $pdo->prepare(
    "SELECT COUNT(*) FROM apuestas a JOIN partidos p ON p.id = a.id_partido
     WHERE p.id_liga = ? AND (p.id_equipo_local = ? OR p.id_equipo_visita = ?)"
);
foreach ($mapeo as $idViejo => $idNuevo) {
    $stmtApuestas->execute([ID_LIGA_FUTURO, $idNuevo, $idNuevo]);
    $cant = (int)$stmtApuestas->fetchColumn();
    echo "  Apuestas sobre partidos ahora apuntando a #{$idNuevo}: {$cant} (sin tocar, referencian id_partido)\n";
}
echo "\n";

echo "=== PASO 4: Recalculando tabla_posiciones para liga " . ID_LIGA_FUTURO . " ===\n";
$repo = new Repository($pdo, $logger);
$repo->recalcularTablaPosiciones(ID_LIGA_FUTURO);
echo "Listo.\n\n";

echo "=== PASO 5: Limpieza de equipos duplicados huerfanos ===\n";
$tablasARevisar = [
    'partidos_local'    => 'SELECT COUNT(*) FROM partidos WHERE id_equipo_local = ?',
    'partidos_visita'   => 'SELECT COUNT(*) FROM partidos WHERE id_equipo_visita = ?',
    'jugadores'         => 'SELECT COUNT(*) FROM jugadores WHERE id_equipo = ?',
    'estad_jugador_part'=> 'SELECT COUNT(*) FROM estadisticas_jugador_partido WHERE id_jugador IN (SELECT id FROM jugadores WHERE id_equipo = ?)',
    'estad_jugador_temp'=> 'SELECT COUNT(*) FROM estadisticas_jugador_temporada WHERE id_jugador IN (SELECT id FROM jugadores WHERE id_equipo = ?)',
    'tabla_posiciones'  => 'SELECT COUNT(*) FROM tabla_posiciones WHERE id_equipo = ?',
];

$stmtNombre = $pdo->prepare('SELECT nombre FROM equipos WHERE id = ?');
$stmtBorrar = $pdo->prepare('DELETE FROM equipos WHERE id = ?');

$borrados = 0;
$noBorrados = [];

foreach ($mapeo as $idViejo => $idNuevo) {
    $stmtNombre->execute([$idViejo]);
    $nombre = $stmtNombre->fetchColumn();
    if ($nombre === false) {
        echo "  #{$idViejo}: ya no existe en equipos (borrado en una corrida anterior), se omite.\n";
        continue;
    }

    $referenciasEncontradas = [];
    foreach ($tablasARevisar as $etiqueta => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idViejo]);
            $cant = (int)$stmt->fetchColumn();
            if ($cant > 0) {
                $referenciasEncontradas[] = "{$etiqueta}={$cant}";
            }
        } catch (Throwable $e) {
            $referenciasEncontradas[] = "{$etiqueta}=ERROR(" . $e->getMessage() . ")";
        }
    }

    if (empty($referenciasEncontradas)) {
        $stmtBorrar->execute([$idViejo]);
        echo "  BORRADO   #{$idViejo} '{$nombre}' (sin referencias pendientes)\n";
        $borrados++;
    } else {
        echo "  NO BORRADO #{$idViejo} '{$nombre}' -- todavia tiene referencias: " . implode(', ', $referenciasEncontradas) . "\n";
        $noBorrados[] = "#{$idViejo} '{$nombre}': " . implode(', ', $referenciasEncontradas);
    }
}

echo "\n=== RESUMEN FINAL ===\n";
echo "Partidos migrados (filas actualizadas): {$actualizados}\n";
echo "Equipos duplicados borrados: {$borrados}\n";
echo "Equipos duplicados NO borrados (revisar a mano): " . count($noBorrados) . "\n";
foreach ($noBorrados as $linea) {
    echo "  - {$linea}\n";
}
echo "\nRecordatorio: Chelsea (3 -> 108) queda pendiente de revision manual,\n";
echo "no se incluyo en este script porque las plantillas no coinciden lo\n";
echo "suficiente como para asumir que es el mismo duplicado.\n";
echo "\nListo. Revisar el log para confirmar antes de dar el pendiente por cerrado.\n";