<?php

/**
 * migrar_fuentes_api_sports.php
 *
 * Paso 2 del parche de fondo (ver ApiSportsSeeder.php).
 *
 * Hasta ahora, TODO lo sembrado con ApiSportsSeeder (Premier League, La Liga,
 * Serie A, NBA, MLB) quedo en external_ids con fuente='api-sports' generico,
 * sin importar el deporte. El seeder ya fue parcheado para usar un fuente
 * propio por deporte de aca en adelante (api-sports-futbol,
 * api-sports-basketball, api-sports-baseball, api-sports-nfl).
 *
 * Si no migramos los external_ids YA existentes, la proxima vez que corras
 * el seeder para una liga que ya sembraste (o cualquier liga del mismo
 * deporte), Repository::upsertX() va a buscar por el fuente NUEVO, no va a
 * encontrar nada, y va a INSERTAR todo de nuevo -- duplicando equipos,
 * ligas, jugadores y partidos en vez de actualizarlos.
 *
 * Este script recorre external_ids donde fuente='api-sports' y, para cada
 * fila, mira el id_deporte real de la entidad a la que apunta (via la
 * tabla de dominio correspondiente) y le pone el fuente especifico que le
 * toca. 'pais' y 'estadio' no son especificos de un deporte, asi que se
 * dejan como estaban.
 *
 * Es seguro correrlo mas de una vez (si ya no hay filas con fuente=
 * 'api-sports', simplemente no migra nada).
 *
 * Uso: php migrar_fuentes_api_sports.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}

const MAPA_DEPORTE_FUENTE = [
    1 => 'api-sports-futbol',
    2 => 'api-sports-basketball',
    3 => 'api-sports-baseball',
    4 => 'api-sports-nfl',
];

try {
    $pdo = db();

    $stmtUpdate = $pdo->prepare(
        "UPDATE external_ids SET fuente = :fuente_nuevo
         WHERE id = :id"
    );

    $totalesPorFuente = [];
    $sinResolver = 0;

    // ------------------------------------------------------------------
    // equipo: id_deporte esta directo en la tabla equipos
    // ------------------------------------------------------------------
    $rows = $pdo->query("
        SELECT ei.id AS ext_id, eq.id_deporte
        FROM external_ids ei
        JOIN equipos eq ON eq.id = ei.entidad_id
        WHERE ei.entidad = 'equipo' AND ei.fuente = 'api-sports'
    ")->fetchAll();
    foreach ($rows as $r) {
        $fuenteNueva = MAPA_DEPORTE_FUENTE[(int)$r['id_deporte']] ?? null;
        if ($fuenteNueva === null) { $sinResolver++; continue; }
        $stmtUpdate->execute([':fuente_nuevo' => $fuenteNueva, ':id' => $r['ext_id']]);
        $totalesPorFuente[$fuenteNueva] = ($totalesPorFuente[$fuenteNueva] ?? 0) + 1;
    }

    // ------------------------------------------------------------------
    // liga: id_deporte esta directo en la tabla ligas_torneos
    // ------------------------------------------------------------------
    $rows = $pdo->query("
        SELECT ei.id AS ext_id, lt.id_deporte
        FROM external_ids ei
        JOIN ligas_torneos lt ON lt.id = ei.entidad_id
        WHERE ei.entidad = 'liga' AND ei.fuente = 'api-sports'
    ")->fetchAll();
    foreach ($rows as $r) {
        $fuenteNueva = MAPA_DEPORTE_FUENTE[(int)$r['id_deporte']] ?? null;
        if ($fuenteNueva === null) { $sinResolver++; continue; }
        $stmtUpdate->execute([':fuente_nuevo' => $fuenteNueva, ':id' => $r['ext_id']]);
        $totalesPorFuente[$fuenteNueva] = ($totalesPorFuente[$fuenteNueva] ?? 0) + 1;
    }

    // ------------------------------------------------------------------
    // jugador: id_deporte se resuelve pasando por el equipo del jugador
    // ------------------------------------------------------------------
    $rows = $pdo->query("
        SELECT ei.id AS ext_id, eq.id_deporte
        FROM external_ids ei
        JOIN jugadores j ON j.id = ei.entidad_id
        JOIN equipos eq ON eq.id = j.id_equipo
        WHERE ei.entidad = 'jugador' AND ei.fuente = 'api-sports'
    ")->fetchAll();
    foreach ($rows as $r) {
        $fuenteNueva = MAPA_DEPORTE_FUENTE[(int)$r['id_deporte']] ?? null;
        if ($fuenteNueva === null) { $sinResolver++; continue; }
        $stmtUpdate->execute([':fuente_nuevo' => $fuenteNueva, ':id' => $r['ext_id']]);
        $totalesPorFuente[$fuenteNueva] = ($totalesPorFuente[$fuenteNueva] ?? 0) + 1;
    }

    // ------------------------------------------------------------------
    // partido: id_deporte esta directo en la tabla partidos
    // ------------------------------------------------------------------
    $rows = $pdo->query("
        SELECT ei.id AS ext_id, p.id_deporte
        FROM external_ids ei
        JOIN partidos p ON p.id = ei.entidad_id
        WHERE ei.entidad = 'partido' AND ei.fuente = 'api-sports'
    ")->fetchAll();
    foreach ($rows as $r) {
        $fuenteNueva = MAPA_DEPORTE_FUENTE[(int)$r['id_deporte']] ?? null;
        if ($fuenteNueva === null) { $sinResolver++; continue; }
        $stmtUpdate->execute([':fuente_nuevo' => $fuenteNueva, ':id' => $r['ext_id']]);
        $totalesPorFuente[$fuenteNueva] = ($totalesPorFuente[$fuenteNueva] ?? 0) + 1;
    }

    echo "== Migracion de fuentes ==\n";
    if (empty($totalesPorFuente)) {
        echo "(no habia filas con fuente='api-sports' para migrar -- ya estaba migrado, o nunca se corrio el seeder generico)\n";
    } else {
        foreach ($totalesPorFuente as $fuente => $cant) {
            echo "✔ $cant filas -> fuente='$fuente'\n";
        }
    }
    if ($sinResolver > 0) {
        echo "⚠ $sinResolver filas con fuente='api-sports' no se pudieron resolver a un deporte (revisar a mano)\n";
    }

    $restantes = (int)$pdo->query(
        "SELECT COUNT(*) FROM external_ids WHERE fuente = 'api-sports'"
    )->fetchColumn();

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "RESUMEN\n";
    echo "  Filas migradas: " . array_sum($totalesPorFuente) . "\n";
    echo "  Filas restantes con fuente='api-sports': $restantes\n";
    echo "\nSiguiente paso: ya podes correr run_seeder.php --fuente=api-sports\n";
    echo "para cualquier liga (ya sembrada o nueva) sin riesgo de duplicar,\n";
    echo "el seeder ahora escribe con el fuente especifico de cada deporte.\n";
} catch (PDOException $e) {
    echo "✘ Error de base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}