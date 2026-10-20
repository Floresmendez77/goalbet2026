<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * MIGRACION PREMIER LEAGUE — pendiente #2 del proyecto.
 *
 * Contexto: la liga "futuro" de Premier League (id_liga = 1) ya tenia
 * 380 partidos cargados de una sesion ANTERIOR a que existiera el
 * puente de equipos. Esos partidos fueron sembrados apuntando a
 * equipos DUPLICADOS (creados aparte, sin reutilizar los equipos
 * historicos ya existentes de api-sports).
 *
 * Se corrio el puente (puente_equipos_diagnostico.php / _aplicar.php
 * PL 15) y se corrigio a mano el mapeo en external_ids para que
 * apunte a los equipos historicos correctos (ver conversacion: 12
 * equipos tenian el vinculo apuntando al duplicado en vez de al
 * historico, se corrigio con DELETE + INSERT directo en external_ids).
 *
 * Este script:
 *   1. Usa un mapeo fijo (ya confirmado a mano) de id_equipo_duplicado
 *      -> id_equipo_historico_correcto para los 12 casos.
 *   2. Actualiza partidos.id_equipo_local / id_equipo_visita en la
 *      liga 1 (380 partidos) para que apunten al equipo correcto.
 *   3. Recalcula tabla_posiciones para la liga 1.
 *   4. Verifica que cada equipo duplicado haya quedado sin ninguna
 *      referencia en partidos, jugadores, estadisticas_jugador_partido,
 *      estadisticas_jugador_temporada y tabla_posiciones. Si esta
 *      limpio, lo borra. Si todavia tiene alguna referencia colgada
 *      (dato inesperado), NO lo borra y lo deja listado al final para
 *      revision manual -- nunca se borra a ciegas.
 *
 * Es seguro correrlo mas de una vez: si los partidos ya fueron
 * migrados, el UPDATE simplemente no encuentra filas con el id viejo
 * y no hace nada; los equipos duplicados ya borrados tampoco generan
 * error, el script los salta.
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" migrar_partidos_premier.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

const ID_LIGA_FUTURO = 1;

// Mapeo confirmado a mano: id_equipo_duplicado => id_equipo_historico_correcto
$mapeo = [
    1  => 103, // Arsenal
    2  => 115, // Aston Villa
    18 => 112, // Brentford
    17 => 110, // Brighton
    16 => 111, // Crystal Palace
    4  => 105, // Everton
    5  => 100, // Fulham
    7  => 109, // Manchester City
    8  => 97,  // Manchester United
    9  => 98,  // Newcastle United
    15 => 114, // Nottingham Forest
    19 => 99,  // AFC Bournemouth
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
try {
    $stmtLocal = $pdo->prepare(
        'UPDATE partidos SET id_equipo_local = ? WHERE id_liga = ? AND id_equipo_local = ?'
    );
    $stmtVisita = $pdo->prepare(
        'UPDATE partidos SET id_equipo_visita = ? WHERE id_liga = ? AND id_equipo_visita = ?'
    );

    $actualizados = 0;
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
            // Si alguna tabla no existe o no tiene esa columna, no rompemos el
            // script, solo lo anotamos para revisar a mano.
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
echo "\nListo. Revisar el log para confirmar antes de dar el pendiente por cerrado.\n";
