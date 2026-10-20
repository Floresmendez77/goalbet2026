<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$pass = password_hash('Password123!', PASSWORD_DEFAULT);

// Limpiar intentos de login previos para evitar bloqueos por rate limit al inicio
$pdo->exec("DELETE FROM login_intentos WHERE identificador IN ('qa_admin', 'qa_cliente', 'admin@goalbet.local', 'cliente@goalbet.local')");

// Limpiar registros relacionados de usuarios de prueba antes de eliminarlos
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("DELETE a FROM apuestas a JOIN usuarios u ON u.id = a.id_usuario WHERE u.username IN ('qa_admin', 'qa_cliente')");
$pdo->exec("DELETE b FROM boletos b JOIN usuarios u ON u.id = b.id_usuario WHERE u.username IN ('qa_admin', 'qa_cliente')");
$pdo->exec("DELETE r FROM recargas r JOIN usuarios u ON u.id = r.id_usuario WHERE u.username IN ('qa_admin', 'qa_cliente')");
$pdo->exec("DELETE t FROM transacciones t JOIN usuarios u ON u.id = t.id_usuario WHERE u.username IN ('qa_admin', 'qa_cliente')");
$pdo->exec("DELETE FROM usuarios WHERE username IN ('qa_admin', 'qa_cliente')");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Create Admin
$stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, username, email, password, saldo, rol, activo) VALUES ('Admin', 'QA', 'qa_admin', 'admin@goalbet.local', ?, 5000.00, 'admin', 1)");
$stmt->execute([$pass]);

// Create Cliente
$stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, username, email, password, saldo, rol, activo) VALUES ('Cliente', 'QA', 'qa_cliente', 'cliente@goalbet.local', ?, 0.00, 'cliente', 1)");
$stmt->execute([$pass]);
$cli_id = (int)$pdo->lastInsertId();

// Create & approve recarga of 1000 for cliente
$ref = 'REC-TEST-001';
$pdo->prepare("INSERT INTO recargas (id_usuario, monto, metodo, comprobante, referencia, estado) VALUES (?, 1000.00, 'Transferencia bancaria', 'COMP-001', ?, 'aprobada')")->execute([$cli_id, $ref]);

$pdo->prepare("UPDATE usuarios SET saldo = 1000.00 WHERE id = ?")->execute([$cli_id]);

$pdo->prepare("INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion) VALUES (?, 'deposito', 1000.00, 0.00, 1000.00, 'DEP-TEST-001', 'Recarga inicial aprobada QA')")->execute([$cli_id]);

// Asegurar al menos 3 partidos programados a futuro para pruebas de apuestas simples y combinadas
$totalApostables = (int) $pdo->query("SELECT COUNT(*) FROM partidos WHERE estado = 'programado' AND fecha_partido > UTC_TIMESTAMP()")->fetchColumn();

if ($totalApostables < 3) {
    // Equipos de prueba QA
    $pdo->exec("INSERT INTO equipos (id, id_deporte, id_liga, id_pais, nombre, siglas, activo) VALUES
        (99901, 1, 1, 1, 'Arsenal QA', 'ARS', 1),
        (99902, 1, 1, 1, 'Chelsea QA', 'CHE', 1),
        (99903, 1, 1, 1, 'Liverpool QA', 'LIV', 1),
        (99904, 1, 1, 1, 'Man City QA', 'MCI', 1)
        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1");

    // Partidos a futuro
    $pdo->exec("INSERT INTO partidos (id_deporte, id_liga, id_equipo_local, id_equipo_visita, fecha_partido, estado, cuota_local, cuota_empate, cuota_visita, codigo) VALUES
        (1, 1, 99901, 99902, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY), 'programado', 1.85, 3.40, 4.20, 'QA-MATCH-001'),
        (1, 1, 99903, 99904, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY), 'programado', 2.10, 3.50, 3.20, 'QA-MATCH-002'),
        (1, 1, 99901, 99903, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 DAY), 'programado', 1.95, 3.25, 3.80, 'QA-MATCH-003')
        ON DUPLICATE KEY UPDATE estado = 'programado', fecha_partido = VALUES(fecha_partido), cuota_local = VALUES(cuota_local), cuota_empate = VALUES(cuota_empate), cuota_visita = VALUES(cuota_visita)");
    echo "QA fixtures seeded.\n";
}

echo "Test users and environment initialized successfully!\n";
