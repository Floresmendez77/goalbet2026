<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Database.php';
Env::load(__DIR__ . '/.env');

$pdo = Database::connect();

echo "=== Columnas de 'equipos' ===\n";
$cols = $pdo->query("DESCRIBE equipos")->fetchAll();
foreach ($cols as $c) {
    echo " - {$c['Field']} ({$c['Type']})\n";
}

echo "\n=== Columnas de 'jugadores' ===\n";
$cols = $pdo->query("DESCRIBE jugadores")->fetchAll();
foreach ($cols as $c) {
    echo " - {$c['Field']} ({$c['Type']})\n";
}