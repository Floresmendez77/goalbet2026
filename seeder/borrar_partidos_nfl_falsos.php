<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * BORRAR PARTIDOS NFL FALSOS — elimina los partidos de NFL (id_liga=20)
 * que quedaron sembrados con datos de relleno (fecha placeholder
 * '2026-07-15 03:00:15' y cruces que no corresponden a ningun
 * calendario real de temporada) segun el diagnostico hecho con
 * corregir_fechas_nfl.php.
 *
 * Lista fija de IDs (verificados a mano contra el .sql: los 24 que
 * corregir_fechas_nfl.php NO pudo resolver contra el calendario real
 * de ESPN 2026 -- el #6780, Bills vs Steelers, SI se resolvio y no
 * esta en esta lista):
 *
 *   6765,6766,6767,6768,6769,6770,6771,6772,6773,6774,6775,6776,
 *   6777,6778,6779,6781,6782,6783,6784,6785,6786,6787,6788,6789
 *
 * Seguridad (por que no hace un DELETE a ciegas por ID):
 *   1. Antes de borrar, vuelve a chequear en la base VIVA (no en el
 *      .sql) que cada ID siga siendo id_liga=20 -- si alguien ya
 *      corrigio ese partido a mano o el ID fue reciclado, se omite
 *      en vez de borrar cualquier cosa.
 *   2. Chequea si hay apuestas cargadas contra alguno de estos
 *      partidos. Si las hay, ese partido se OMITE del borrado (no se
 *      fuerza el delete) -- se lista para revision manual. En el
 *      .sql original la tabla `apuestas` esta vacia, pero este check
 *      corre contra tu base actual, que puede haber cambiado.
 *   3. `cuotas_historial` y `estadisticas_jugador_partido` tienen
 *      ON DELETE CASCADE hacia `partidos`, asi que se limpian solos
 *      -- no hace falta borrarlos a mano.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para borrar.
 *
 * Uso:
 *   php borrar_partidos_nfl_falsos.php
 *   php borrar_partidos_nfl_falsos.php --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$aplicar = in_array('--aplicar', $argv, true);

const IDS_FALSOS = [
    6765, 6766, 6767, 6768, 6769, 6770, 6771, 6772, 6773, 6774,
    6775, 6776, 6777, 6778, 6779, 6781, 6782, 6783, 6784, 6785,
    6786, 6787, 6788, 6789,
];

$pdo = Database::connect();
$logger = new Logger();

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a borrar de la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a borrar. Agregar --aplicar para ejecutar de verdad ===\n\n";

$placeholders = implode(',', array_fill(0, count(IDS_FALSOS), '?'));

// 1. Traer estado actual de esos IDs (pueden no existir, pueden haber cambiado de liga)
$stmt = $pdo->prepare(
    "SELECT p.id, p.id_liga, p.fecha_partido, e1.nombre AS local, e2.nombre AS visita
     FROM partidos p
     JOIN equipos e1 ON p.id_equipo_local = e1.id
     JOIN equipos e2 ON p.id_equipo_visita = e2.id
     WHERE p.id IN ($placeholders)"
);
$stmt->execute(IDS_FALSOS);
$encontrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
$porId = [];
foreach ($encontrados as $fila) {
    $porId[(int)$fila['id']] = $fila;
}

// 2. Chequear apuestas cargadas contra estos partidos (en la base VIVA)
$stmt = $pdo->prepare(
    "SELECT id_partido, COUNT(*) AS n FROM apuestas WHERE id_partido IN ($placeholders) GROUP BY id_partido"
);
$stmt->execute(IDS_FALSOS);
$conApuestas = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $conApuestas[(int)$fila['id_partido']] = (int)$fila['n'];
}

$aBorrar = [];
$omitidos = 0;

foreach (IDS_FALSOS as $id) {
    if (!isset($porId[$id])) {
        $logger->skipped("partido #$id ya no existe en la base (¿ya borrado antes?)");
        $omitidos++;
        continue;
    }
    $fila = $porId[$id];
    if ((int)$fila['id_liga'] !== 20) {
        $logger->skipped("partido #$id ya no es id_liga=20 (liga actual: {$fila['id_liga']}) -- no se toca, revision manual");
        $omitidos++;
        continue;
    }
    if (isset($conApuestas[$id])) {
        $logger->skipped("partido #$id ({$fila['local']} vs {$fila['visita']}) tiene {$conApuestas[$id]} apuesta(s) cargada(s) -- NO se borra, revision manual");
        $omitidos++;
        continue;
    }
    $aBorrar[] = $id;
    $logger->info("partido #$id ({$fila['local']} vs {$fila['visita']}, {$fila['fecha_partido']}) -> se va a borrar");
}

if (empty($aBorrar)) {
    echo "\nNo hay nada para borrar.\n";
    exit(0);
}

if ($aplicar) {
    $ph = implode(',', array_fill(0, count($aBorrar), '?'));
    $stmtDel = $pdo->prepare("DELETE FROM partidos WHERE id IN ($ph)");
    $stmtDel->execute($aBorrar);
    $borrados = $stmtDel->rowCount();
    foreach ($aBorrar as $id) {
        $logger->updated("partido #$id borrado");
    }
    echo "\n[RESUMEN] borrados: $borrados | omitidos: $omitidos\n";
} else {
    echo "\n[RESUMEN] se borrarian: " . count($aBorrar) . " | omitidos: $omitidos\n";
    echo "Esto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
}