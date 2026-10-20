<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * CERRAR PARTIDOS CON MARCADOR YA GUARDADO — arregla partidos de
 * CUALQUIER liga que quedaron con `estado = 'programado'` (o
 * cualquier otro estado no final) a pesar de que YA tienen
 * marcador_local y marcador_visita cargados y la fecha ya paso.
 *
 * Como se detecto: revisando Champions League 2023-24 encontramos
 * que los 12 partidos "pendientes" en realidad SI tenian el
 * resultado historico real guardado (ej. Man City 1-1 Real Madrid,
 * el marcador real de la ida de cuartos 2024) -- lo unico que
 * faltaba era que `estado` nunca paso de 'programado' a 'finalizado'.
 * Es un bug del proceso de seed original (probablemente crasheo o se
 * corto a mitad de camino), NO datos inventados como el caso de NFL.
 * El mismo patron aparecio en Bundesliga, Ligue 1, Ligue 2, Serie B
 * y Liga MX -- por eso este script es generico (no liga por liga).
 *
 * Que hace:
 *   Por cada partido con estado NOT IN ('finalizado','cancelado',
 *   'suspendido') Y marcador_local/marcador_visita NO nulos Y fecha
 *   ya pasada -> pone estado = 'finalizado'.
 *
 * Por que es seguro:
 *   - No inventa ningun marcador, solo usa el que YA esta guardado.
 *   - El trigger `trg_partidos_resolver_apuestas` liquida las
 *     apuestas pendientes con el cambio de estado a 'finalizado'
 *     (no depende de que cambie el marcador), asi que dispara igual
 *     que si lo hubiera actualizado ESPN.
 *   - Si un partido tiene marcador pero AUN no paso su fecha (dato
 *     corrupto/adelantado), se omite -- no se cierra un partido que
 *     "todavia no se jugo" aunque tenga marcador cargado.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para escribir.
 *
 * Uso:
 *   php cerrar_partidos_con_marcador.php
 *   php cerrar_partidos_con_marcador.php --aplicar
 *   php cerrar_partidos_con_marcador.php --liga=26          (limitar a una sola liga)
 *   php cerrar_partidos_con_marcador.php --liga=26 --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$aplicar = in_array('--aplicar', $argv, true);
$idLigaFiltro = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--liga=')) {
        $idLigaFiltro = (int)substr($arg, strlen('--liga='));
    }
}

$pdo = Database::connect();
$logger = new Logger();

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

$sql = "SELECT p.id, p.id_liga, p.fecha_partido, p.marcador_local, p.marcador_visita, p.estado,
               e1.nombre AS local, e2.nombre AS visita
        FROM partidos p
        JOIN equipos e1 ON p.id_equipo_local = e1.id
        JOIN equipos e2 ON p.id_equipo_visita = e2.id
        WHERE p.estado NOT IN ('finalizado', 'cancelado', 'suspendido')
          AND p.marcador_local IS NOT NULL
          AND p.marcador_visita IS NOT NULL
          AND p.fecha_partido < NOW()";
$params = [];
if ($idLigaFiltro !== null) {
    $sql .= " AND p.id_liga = :liga";
    $params[':liga'] = $idLigaFiltro;
}
$sql .= " ORDER BY p.id_liga, p.fecha_partido";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($candidatos)) {
    echo "No hay partidos en esta condicion. Nada que hacer.\n";
    exit(0);
}

echo count($candidatos) . " partidos con marcador guardado pero estado sin cerrar:\n\n";

$porLiga = [];
foreach ($candidatos as $fila) {
    $porLiga[$fila['id_liga']][] = $fila;
}

$stmtUpdate = $pdo->prepare("UPDATE partidos SET estado = 'finalizado' WHERE id = :id");
$totalCerrados = 0;

foreach ($porLiga as $idLiga => $filas) {
    echo "--- liga $idLiga (" . count($filas) . " partidos) ---\n";
    foreach ($filas as $fila) {
        $linea = "partido #{$fila['id']} {$fila['local']} {$fila['marcador_local']}-{$fila['marcador_visita']} {$fila['visita']} ({$fila['fecha_partido']}, estado actual: {$fila['estado']})";
        if ($aplicar) {
            $stmtUpdate->execute([':id' => $fila['id']]);
            $logger->updated("$linea -> finalizado");
            $totalCerrados++;
        } else {
            $logger->info("SE CERRARIA: $linea");
        }
    }
}

echo "\n";
if ($aplicar) {
    echo "[RESUMEN] cerrados: $totalCerrados\n";
} else {
    echo "[RESUMEN] se cerrarian: " . count($candidatos) . "\n";
    echo "Esto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
}