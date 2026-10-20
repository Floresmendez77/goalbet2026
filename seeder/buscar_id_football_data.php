<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/buscar_id_football_data.php
// Busca el id de football-data vinculado a un equipo por nombre.
// NO modifica nada.
//
// Correr: & "C:\MAMP\bin\php\php8.3.1\php.exe" buscar_id_football_data.php "Valencia"

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');
$pdo = Database::connect();

$nombreBuscado = $argv[1] ?? null;
if (!$nombreBuscado) {
    echo "Uso: php buscar_id_football_data.php <nombre_parcial_del_equipo>\n";
    exit(1);
}

$stmt = $pdo->prepare("SELECT id, nombre FROM equipos WHERE nombre LIKE ?");
$stmt->execute(["%$nombreBuscado%"]);
$equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equipos)) {
    echo "No se encontro ningun equipo con nombre parecido a '$nombreBuscado'.\n";
    exit(1);
}

$stmtExternal = $pdo->prepare(
    "SELECT external_id FROM external_ids WHERE fuente = 'football-data' AND entidad = 'equipo' AND entidad_id = ?"
);

foreach ($equipos as $eq) {
    $stmtExternal->execute([$eq['id']]);
    $idFD = $stmtExternal->fetchColumn();
    echo "Equipo #{$eq['id']} '{$eq['nombre']}' -> id football-data: " . ($idFD !== false ? $idFD : 'SIN VINCULO') . "\n";
}