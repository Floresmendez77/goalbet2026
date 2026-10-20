<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');

echo "== Probando conexion con estos valores ==\n";
echo "HOST: " . Env::get('DB_HOST', '127.0.0.1') . "\n";
echo "PORT: " . Env::get('DB_PORT', '3306') . "\n";
echo "NAME: " . Env::get('DB_NAME', 'goalbet2026') . "\n";
echo "USER: " . Env::get('DB_USER', 'root') . "\n";
echo "PASS: " . (Env::get('DB_PASS', '') === '' ? '(vacio)' : '(configurado)') . "\n\n";

try {
    $pdo = Database::connect();
    echo "OK: Conexion exitosa a la base '" . Env::get('DB_NAME', 'goalbet2026') . "'\n\n";

    $stmt = $pdo->query("SHOW TABLES");
    $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($tablas) === 0) {
        echo "AVISO: La base existe pero esta VACIA - el schema.sql no se importo todavia.\n";
    } else {
        echo "Tablas encontradas (" . count($tablas) . "):\n";
        foreach ($tablas as $t) {
            echo "   - $t\n";
        }
        if (in_array('deportes', $tablas)) {
            $count = $pdo->query("SELECT COUNT(*) FROM deportes")->fetchColumn();
            echo "\nTabla 'deportes' tiene $count filas (deberia tener 4)\n";
        }
        if (in_array('equipos', $tablas)) {
            $count = $pdo->query("SELECT COUNT(*) FROM equipos")->fetchColumn();
            echo "Tabla 'equipos' tiene $count filas\n";
        }
    }
} catch (PDOException $e) {
    echo "ERROR DE CONEXION:\n";
    echo "   " . $e->getMessage() . "\n\n";
    $msg = $e->getMessage();
    if (str_contains($msg, 'Access denied')) {
        echo "Diagnostico: usuario o contrasena incorrectos.\n";
    } elseif (str_contains($msg, 'Unknown database')) {
        echo "Diagnostico: la base 'goalbet2026' no existe todavia. Importa schema.sql primero.\n";
    } elseif (str_contains($msg, 'refused') || str_contains($msg, '10061')) {
        echo "Diagnostico: MAMP no esta corriendo o el puerto esta mal.\n";
    }
}