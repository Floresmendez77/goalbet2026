<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_champions_pendiente.php
// Verifica el estado de Arsenal(103), Man City(109) y Newcastle(98)
// -- los equipos historicos -- en la liga Champions League (id=8),
// comparado contra sus duplicados (1, 7, 9) que todavia tienen
// partidos y tabla_posiciones ahi. NO modifica nada.

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');
$pdo = Database::connect();

const ID_LIGA_CHAMPIONS = 8;

// Duplicado => historico, segun el mismo mapeo ya usado en Premier League
$candidatos = [
    1 => 103, // Arsenal
    7 => 109, // Manchester City
    9 => 98,  // Newcastle (a confirmar)
];

foreach ($candidatos as $idDup => $idHist) {
    $stmtNombre = $pdo->prepare('SELECT nombre FROM equipos WHERE id = ?');

    $stmtNombre->execute([$idDup]);
    $nombreDup = $stmtNombre->fetchColumn();
    $stmtNombre->execute([$idHist]);
    $nombreHist = $stmtNombre->fetchColumn();

    echo "--- Duplicado #$idDup '$nombreDup'  vs  Histórico #$idHist '$nombreHist' ---\n";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partidos WHERE id_liga = ? AND (id_equipo_local = ? OR id_equipo_visita = ?)");
    $stmt->execute([ID_LIGA_CHAMPIONS, $idDup, $idDup]);
    echo "  Partidos del duplicado en Champions: " . $stmt->fetchColumn() . "\n";

    $stmt->execute([ID_LIGA_CHAMPIONS, $idHist, $idHist]);
    echo "  Partidos del histórico en Champions: " . $stmt->fetchColumn() . "\n";

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM tabla_posiciones WHERE id_liga = ? AND id_equipo = ?");
    $stmt2->execute([ID_LIGA_CHAMPIONS, $idDup]);
    echo "  Filas tabla_posiciones del duplicado: " . $stmt2->fetchColumn() . "\n";

    $stmt2->execute([ID_LIGA_CHAMPIONS, $idHist]);
    echo "  Filas tabla_posiciones del histórico: " . $stmt2->fetchColumn() . "\n";

    echo "\n";
}

echo "=== FIN (no se modifico nada) ===\n";