<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require 'Env.php';
require 'Database.php';
Env::load('.env');
$pdo = Database::connect();

$ligas = [24 => 'Ligue 2', 25 => 'Serie B', 26 => 'Champions', 27 => 'Brasileirao', 29 => 'Liga MX'];

foreach ($ligas as $id => $nombre) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT e.id) as con_imagen
         FROM estadios e
         INNER JOIN equipos eq ON eq.id_estadio = e.id
         WHERE eq.id_liga = ? AND e.imagen LIKE '%wikimedia%'"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "$nombre (liga=$id): $r[con_imagen] estadios con imagen de Wikipedia\n";
}
