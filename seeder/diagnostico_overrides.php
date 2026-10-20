<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require 'Env.php';
require 'Database.php';
Env::load('.env');
$pdo = Database::connect();

echo "=== Licorne ===\n";
$r1 = $pdo->query("SELECT id, nombre, ciudad, HEX(nombre) as hex FROM estadios WHERE nombre LIKE '%Licorne%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($r1);

echo "=== Red Bull Arena ===\n";
$r2 = $pdo->query("SELECT e.id, e.nombre, e.ciudad, eq.nombre as equipo, eq.id_liga FROM estadios e INNER JOIN equipos eq ON eq.id_estadio = e.id WHERE e.nombre = 'Red Bull Arena'")->fetchAll(PDO::FETCH_ASSOC);
print_r($r2);
