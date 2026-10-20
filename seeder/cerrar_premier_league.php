<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/cerrar_premier_league.php
//
// Ultimo paso para cerrar el pendiente #2 (Premier League).
// Los 12 equipos duplicados ya quedaron en 10 borrados / 2 pendientes
// (migrar_partidos_premier.php). Los 2 que faltan (Everton dup #4,
// Fulham dup #5) estaban bloqueados solo por un jugador cada uno:
//   - David Moyes (Everton dup)  -- entrenador, no jugador de cancha
//   - Alvaro Arbeloa (Fulham dup) -- retirado, no juega actualmente
//
// Este script:
//   1. Borra esos 2 registros puntuales de la tabla jugadores.
//   2. Verifica que Everton dup y Fulham dup queden sin ninguna
//      referencia pendiente (misma logica de chequeo que ya usamos).
//   3. Si estan limpios, borra los 2 equipos. Si no, lo dice y no
//      borra nada mas -- nunca a ciegas.
//
// Correr desde la carpeta seeder/ con:
//   & "C:\MAMP\bin\php\php8.3.1\php.exe" cerrar_premier_league.php

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');
$pdo = Database::connect();

$casos = [
    4 => ['nombre_equipo' => 'Everton FC', 'jugador' => 'David Moyes'],
    5 => ['nombre_equipo' => 'Fulham FC',  'jugador' => 'Álvaro Arbeloa'],
];

echo "=== PASO 1: Borrando jugadores puntuales (staff/retirados) ===\n";
foreach ($casos as $idEquipo => $info) {
    $stmt = $pdo->prepare('SELECT id FROM jugadores WHERE id_equipo = ? AND nombre = ?');
    $stmt->execute([$idEquipo, $info['jugador']]);
    $idJugador = $stmt->fetchColumn();

    if ($idJugador === false) {
        echo "  Equipo #{$idEquipo}: no se encontro a '{$info['jugador']}' (¿ya se borro antes?), se omite.\n";
        continue;
    }

    $pdo->prepare('DELETE FROM jugadores WHERE id = ?')->execute([$idJugador]);
    echo "  BORRADO jugador '{$info['jugador']}' (id {$idJugador}) del equipo #{$idEquipo}\n";
}

echo "\n=== PASO 2: Verificacion final y borrado de los 2 equipos ===\n";

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

foreach ($casos as $idEquipo => $info) {
    $stmtNombre->execute([$idEquipo]);
    $nombre = $stmtNombre->fetchColumn();
    if ($nombre === false) {
        echo "  #{$idEquipo}: ya no existe en equipos (borrado antes), se omite.\n";
        continue;
    }

    $referenciasEncontradas = [];
    foreach ($tablasARevisar as $etiqueta => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idEquipo]);
        $cant = (int)$stmt->fetchColumn();
        if ($cant > 0) {
            $referenciasEncontradas[] = "{$etiqueta}={$cant}";
        }
    }

    if (empty($referenciasEncontradas)) {
        $stmtBorrar->execute([$idEquipo]);
        echo "  BORRADO   #{$idEquipo} '{$nombre}' (sin referencias pendientes)\n";
        $borrados++;
    } else {
        echo "  NO BORRADO #{$idEquipo} '{$nombre}' -- todavia tiene: " . implode(', ', $referenciasEncontradas) . "\n";
        $noBorrados[] = "#{$idEquipo} '{$nombre}'";
    }
}

echo "\n=== RESUMEN ===\n";
echo "Equipos borrados ahora: {$borrados}\n";
echo "Equipos NO borrados: " . count($noBorrados) . "\n";
foreach ($noBorrados as $l) {
    echo "  - {$l}\n";
}

if ($borrados === 2) {
    echo "\nListo. Pendiente #2 (Premier League) CERRADO por completo: los 12 equipos duplicados fueron resueltos.\n";
}