<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_partidos_pendientes.php
// Para los equipos duplicados que todavia muestran partidos_local /
// partidos_visita / tabla_posiciones > 0 (fuera de la liga 1, que ya
// esta migrada), muestra en que liga estan esas referencias.
// NO borra ni modifica nada.

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');
$pdo = Database::connect();

$idsAVerificar = [1, 2, 18, 17, 16, 4, 5, 7, 8, 9, 15, 19];

foreach ($idsAVerificar as $id) {
    $stmtNombre = $pdo->prepare('SELECT nombre FROM equipos WHERE id = ?');
    $stmtNombre->execute([$id]);
    $nombre = $stmtNombre->fetchColumn();
    if ($nombre === false) {
        continue;
    }

    echo "--- #$id '$nombre' ---\n";

    $stmt = $pdo->prepare("
        SELECT p.id_liga, l.nombre AS liga_nombre, COUNT(*) AS cant
        FROM partidos p
        JOIN ligas_torneos l ON l.id = p.id_liga
        WHERE p.id_equipo_local = ? OR p.id_equipo_visita = ?
        GROUP BY p.id_liga, l.nombre
    ");
    $stmt->execute([$id, $id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "  Sin partidos referenciados.\n";
    }
    foreach ($rows as $r) {
        echo "  Partidos en liga #{$r['id_liga']} '{$r['liga_nombre']}': {$r['cant']}\n";
    }

    $stmt = $pdo->prepare("
        SELECT tp.id_liga, l.nombre AS liga_nombre
        FROM tabla_posiciones tp
        JOIN ligas_torneos l ON l.id = tp.id_liga
        WHERE tp.id_equipo = ?
    ");
    $stmt->execute([$id]);
    $rows2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows2 as $r) {
        echo "  Fila en tabla_posiciones de liga #{$r['id_liga']} '{$r['liga_nombre']}'\n";
    }

    echo "\n";
}

echo "=== FIN (no se modifico nada) ===\n";