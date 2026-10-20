<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Database.php';
Env::load(__DIR__ . '/.env');

$pdo = Database::connect();

$sql = "
    SELECT lt.nombre AS liga, COUNT(DISTINCT e.id) AS equipos, COUNT(j.id) AS jugadores
    FROM ligas_torneos lt
    LEFT JOIN equipos e ON e.liga_id = lt.id
    LEFT JOIN jugadores j ON j.equipo_id = e.id
    GROUP BY lt.id, lt.nombre
    ORDER BY lt.nombre
";
$rows = $pdo->query($sql)->fetchAll();

foreach ($rows as $r) {
    echo "{$r['liga']}: {$r['equipos']} equipos, {$r['jugadores']} jugadores\n";
}