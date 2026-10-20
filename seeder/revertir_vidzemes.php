<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require 'Env.php';
require 'Database.php';
Env::load('.env');
$pdo = Database::connect();

$stmt = $pdo->prepare("UPDATE estadios SET imagen = NULL WHERE nombre = 'Vidzemes Olimpiskais centrs'");
$ok = $stmt->execute();
echo $ok ? "Revertido: imagen puesta en NULL para Vidzemes Olimpiskais centrs.\n" : "Fallo el UPDATE.\n";
