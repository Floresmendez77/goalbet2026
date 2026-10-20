<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require 'Env.php';
require 'Database.php';
Env::load('.env');
$pdo = Database::connect();

// Lista completa de estadios de Champions con su imagen actual, para
// comparar contra el log del dry-run y encontrar cual quedo afuera.
$stmt = $pdo->prepare(
    "SELECT DISTINCT e.id, e.nombre, e.ciudad, e.imagen
     FROM estadios e
     INNER JOIN equipos eq ON eq.id_estadio = e.id
     WHERE eq.id_liga = 26
     ORDER BY e.nombre"
);
$stmt->execute();
$estadios = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total estadios liga=26: " . count($estadios) . "\n\n";
foreach ($estadios as $e) {
    $tieneWiki = (strpos($e['imagen'] ?? '', 'wikimedia') !== false) ? 'SI' : 'NO';
    echo "[$tieneWiki] {$e['nombre']} ({$e['ciudad']}) -> " . ($e['imagen'] ?? 'NULL') . "\n";
}
