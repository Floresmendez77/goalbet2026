<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/buscar_id_equipo.php
//
// Por que existe: para usar vincular_equipo_espn.php hace falta el id
// interno (tabla equipos.id) del equipo en tu base, y no siempre lo
// tenes a mano. En vez de usar el cliente `mysql` de linea de comandos
// (que en MAMP no esta en el PATH de Windows por default), este script
// reutiliza la MISMA conexion que ya usa el resto del seeder (Database.php
// + .env), asi no hace falta configurar nada nuevo ni pasar credenciales
// a mano. Solo lectura, no modifica nada.
//
// Uso:
//   php buscar_id_equipo.php <id_liga_bd> <pedazo_del_nombre>
//
// Ejemplos:
//   php buscar_id_equipo.php 30 Galaxy
//   php buscar_id_equipo.php 30 "Los Angeles"
//   php buscar_id_equipo.php 30 ""     -- (vacio) lista TODOS los equipos de esa liga

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';

Env::load(__DIR__ . '/.env');

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$pedazoNombre = $argv[2] ?? '';

if (!$idLigaBd) {
    echo "Uso: php buscar_id_equipo.php <id_liga_bd> <pedazo_del_nombre>\n";
    echo "Ejemplo: php buscar_id_equipo.php 30 Galaxy\n";
    exit(1);
}

$pdo = Database::connect();

if ($pedazoNombre === '') {
    $stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = :liga ORDER BY nombre');
    $stmt->execute([':liga' => $idLigaBd]);
} else {
    $stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = :liga AND nombre LIKE :nombre ORDER BY nombre');
    $stmt->execute([':liga' => $idLigaBd, ':nombre' => '%' . $pedazoNombre . '%']);
}

$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($filas)) {
    echo "No se encontraron equipos con id_liga=$idLigaBd" . ($pedazoNombre !== '' ? " y nombre like '%$pedazoNombre%'" : '') . ".\n";
    exit(0);
}

echo "id\tnombre\n";
foreach ($filas as $f) {
    echo $f['id'] . "\t" . $f['nombre'] . "\n";
}