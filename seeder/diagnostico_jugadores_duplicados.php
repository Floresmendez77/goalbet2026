<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_jugadores_duplicados.php
// Diagnóstico previo a la limpieza de los 12 equipos duplicados de Premier League.
// NO borra nada. Solo reporta.

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$pdo = Database::connect();

// Mapeo equipo_duplicado_id => equipo_historico_id (los 12 ya resueltos)
$mapeo = [
    1  => 103, // Arsenal
    2  => 115, // Aston Villa
    18 => 112, // Brentford
    17 => 110, // Brighton
    16 => 111, // Crystal Palace
    4  => 105, // Everton
    5  => 100, // Fulham
    7  => 109, // Man City
    8  => 97,  // Man United
    9  => 98,  // Newcastle
    15 => 114, // Nottm Forest
    19 => 99,  // Bournemouth
];

echo "=== DIAGNOSTICO JUGADORES DUPLICADOS - PREMIER LEAGUE ===\n\n";

foreach ($mapeo as $idDup => $idHist) {

    // 1. Traer jugadores del equipo duplicado
    $stmt = $pdo->prepare("SELECT id, nombre FROM jugadores WHERE id_equipo = ?");
    $stmt->execute([$idDup]);
    $jugadoresDup = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jugadoresDup)) {
        echo "Equipo duplicado #$idDup: sin jugadores. OK para revisar borrado directo.\n\n";
        continue;
    }

    $idsJugadoresDup = array_column($jugadoresDup, 'id');
    $placeholders = implode(',', array_fill(0, count($idsJugadoresDup), '?'));

    // 2. Chequear estadisticas_jugador_partido (vía jugadores intermedia)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, COUNT(DISTINCT id_jugador) AS jugadores_con_stats
        FROM estadisticas_jugador_partido
        WHERE id_jugador IN ($placeholders)
    ");
    $stmt->execute($idsJugadoresDup);
    $statsPartido = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Chequear estadisticas_jugador_temporada (vía jugadores intermedia)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, COUNT(DISTINCT id_jugador) AS jugadores_con_stats
        FROM estadisticas_jugador_temporada
        WHERE id_jugador IN ($placeholders)
    ");
    $stmt->execute($idsJugadoresDup);
    $statsTemporada = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Traer jugadores del equipo histórico para comparar nombres
    $stmt = $pdo->prepare("SELECT nombre FROM jugadores WHERE id_equipo = ?");
    $stmt->execute([$idHist]);
    $nombresHist = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nombre');

    // 5. Marcar cuáles del duplicado tienen nombre igual en el histórico
    //    (posible copia exacta) vs cuáles no aparecen (posible jugador único)
    $coinciden = 0;
    $noConciden = [];
    foreach ($jugadoresDup as $j) {
        if (in_array($j['nombre'], $nombresHist)) {
            $coinciden++;
        } else {
            $noConciden[] = $j['nombre'];
        }
    }

    echo "--- Equipo duplicado #$idDup -> histórico #$idHist ---\n";
    echo "Jugadores en duplicado: " . count($jugadoresDup) . "\n";
    echo "  Coinciden por nombre con histórico: $coinciden\n";
    echo "  NO coinciden (revisar a mano): " . count($noConciden) . "\n";
    if (!empty($noConciden)) {
        echo "    -> " . implode(', ', $noConciden) . "\n";
    }
    echo "Stats en estadisticas_jugador_partido: {$statsPartido['total']} filas, "
       . "{$statsPartido['jugadores_con_stats']} jugadores distintos\n";
    echo "Stats en estadisticas_jugador_temporada: {$statsTemporada['total']} filas, "
       . "{$statsTemporada['jugadores_con_stats']} jugadores distintos\n\n";
}

echo "=== FIN DIAGNOSTICO (no se borró ni modificó nada) ===\n";