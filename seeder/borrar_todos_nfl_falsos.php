<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * BORRAR TODOS LOS PARTIDOS NFL FALSOS (API-%) — version generalizada
 * de borrar_partidos_nfl_falsos.php.
 *
 * Contexto: el primer borrado (borrar_partidos_nfl_falsos.php) uso una
 * lista fija de 24 IDs -- los que tenian fecha_partido EXACTAMENTE
 * '2026-07-15 03:00:15'. Pero el seed original de NFL genero muchos
 * mas partidos de relleno con el MISMO patron de fecha placeholder
 * pero segundos distintos ('2026-07-15 03:00:04' hasta '...:14'),
 * la mayoria marcados 'finalizado' con marcador INVENTADO. Ese es el
 * grupo que este script termina de limpiar.
 *
 * Por que es seguro borrar TODOS los `API-%` de NFL sin revisar cruce
 * por cruce (a diferencia del primer borrado, que goteaba uno por
 * uno): porque poblar_partidos_nfl_espn.php YA sembro el calendario
 * REAL completo de la temporada regular 2026 (272 partidos, codigo
 * `ESP-%`) -- 32 equipos x 17 partidos / 2 = 272, el numero exacto de
 * partidos de una temporada regular de NFL. Si el calendario real ya
 * esta completo, ningun `API-%` que quede puede ser un partido real
 * pendiente: o es un duplicado de un partido que `ESP-%` ya tiene
 * bien, o es un cruce que nunca se jugo en la temporada real (dato de
 * relleno inventado por el seed original).
 *
 * Identifica los partidos por `id_liga = 20 AND codigo LIKE 'API-%'`
 * -- NO por fecha, para no depender de que el placeholder siga siendo
 * exactamente el mismo string.
 *
 * Seguridad (mismo criterio que el primer borrado):
 *   1. Vuelve a chequear en la base VIVA que cada partido siga siendo
 *      id_liga=20 y codigo LIKE 'API-%' antes de tocarlo.
 *   2. Si algun partido tiene apuestas cargadas, se OMITE (no se
 *      fuerza el delete) -- se lista para revision manual.
 *   3. `cuotas_historial` y `estadisticas_jugador_partido` tienen
 *      ON DELETE CASCADE hacia `partidos`, se limpian solos.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para borrar.
 *
 * Uso:
 *   php borrar_todos_nfl_falsos.php
 *   php borrar_todos_nfl_falsos.php --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$aplicar = in_array('--aplicar', $argv, true);

$pdo = Database::connect();
$logger = new Logger();

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a borrar de la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a borrar. Agregar --aplicar para ejecutar de verdad ===\n\n";

// 1. Traer todos los partidos NFL falsos (API-%) de la base viva
$stmt = $pdo->prepare(
    "SELECT p.id, p.codigo, p.fecha_partido, p.estado, e1.nombre AS local, e2.nombre AS visita
     FROM partidos p
     JOIN equipos e1 ON p.id_equipo_local = e1.id
     JOIN equipos e2 ON p.id_equipo_visita = e2.id
     WHERE p.id_liga = 20 AND p.codigo LIKE 'API-%'
     ORDER BY p.id"
);
$stmt->execute();
$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($candidatos)) {
    echo "No hay partidos NFL con codigo API-% en la base. Nada que hacer.\n";
    exit(0);
}

echo count($candidatos) . " partidos NFL falsos (API-%) encontrados en la base.\n\n";

// 2. Chequear apuestas cargadas contra estos partidos
$ids = array_column($candidatos, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT id_partido, COUNT(*) AS n FROM apuestas WHERE id_partido IN ($placeholders) GROUP BY id_partido"
);
$stmt->execute($ids);
$conApuestas = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $conApuestas[(int)$fila['id_partido']] = (int)$fila['n'];
}

$aBorrar = [];
$omitidos = 0;

foreach ($candidatos as $fila) {
    $id = (int)$fila['id'];
    if (isset($conApuestas[$id])) {
        $logger->skipped("partido #$id ({$fila['local']} vs {$fila['visita']}, {$fila['codigo']}) tiene {$conApuestas[$id]} apuesta(s) cargada(s) -- NO se borra, revision manual");
        $omitidos++;
        continue;
    }
    $aBorrar[] = $id;
}

// Log resumido: mostrar solo un resumen por bloques de 20 para no saturar la terminal
$logger->info("Se van a borrar " . count($aBorrar) . " partidos (IDs " . reset($aBorrar) . " a " . end($aBorrar) . ", con huecos donde hubo duplicados/omitidos).");

if (empty($aBorrar)) {
    echo "\nNo hay nada para borrar (todos tenian apuestas cargadas).\n";
    exit(0);
}

if ($aplicar) {
    // Borrar en lotes de 200 para no mandar un IN() gigante de una sola vez
    $borrados = 0;
    foreach (array_chunk($aBorrar, 200) as $lote) {
        $ph = implode(',', array_fill(0, count($lote), '?'));
        $stmtDel = $pdo->prepare("DELETE FROM partidos WHERE id IN ($ph)");
        $stmtDel->execute($lote);
        $borrados += $stmtDel->rowCount();
    }
    $logger->updated("$borrados partidos NFL falsos borrados");
    echo "\n[RESUMEN] borrados: $borrados | omitidos: $omitidos\n";
} else {
    echo "\n[RESUMEN] se borrarian: " . count($aBorrar) . " | omitidos: $omitidos\n";
    echo "Esto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
}