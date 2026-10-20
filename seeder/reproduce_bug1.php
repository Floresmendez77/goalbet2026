<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();

// Reset test data for match 1
$pdo->exec("UPDATE partidos SET estado = 'programado', marcador_local = NULL, marcador_visita = NULL WHERE id = 1");
$pdo->exec("DELETE FROM apuestas WHERE id_partido = 1");

// Get customer qa_cliente
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = 'qa_cliente'");
$stmt->execute();
$cliente = $stmt->fetch();

echo "Initial cliente balance: " . $cliente['saldo'] . "\n";

// Place bet of 100 on match 1
$pdo->beginTransaction();
$saldo_actual = (float)$cliente['saldo'];
$monto = 100.00;
$saldo_nuevo = $saldo_actual - $monto;
$pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?")->execute([$saldo_nuevo, $cliente['id']]);
$ref_apuesta = generar_referencia('APU');
$pdo->prepare("INSERT INTO apuestas (id_usuario, id_partido, tipo_apuesta, monto, cuota, referencia, estado) VALUES (?, 1, 'local', ?, 2.00, ?, 'pendiente')")
    ->execute([$cliente['id'], $monto, $ref_apuesta]);
$id_apuesta = $pdo->lastInsertId();
$ref_tx = generar_referencia('TXA');
$pdo->prepare("INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion) VALUES (?, 'apuesta', ?, ?, ?, ?, 'Apuesta test bug 1')")
    ->execute([$cliente['id'], $monto, $saldo_actual, $saldo_nuevo, $ref_tx]);
$pdo->commit();

echo "Bet #$id_apuesta placed. Balance after bet: $saldo_nuevo\n";

// Admin updates match status to 'pospuesto'
$pdo->prepare("UPDATE partidos SET estado = 'pospuesto' WHERE id = 1")->execute();

// Check bet state after match postponed
$stmt = $pdo->prepare("SELECT * FROM apuestas WHERE id = ?");
$stmt->execute([$id_apuesta]);
$apuesta = $stmt->fetch();

$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$cliente['id']]);
$saldo_final = $stmt->fetchColumn();

echo "Match status: pospuesto\n";
echo "Bet status in DB: " . $apuesta['estado'] . "\n";
echo "Cliente balance after postponed match: $saldo_final\n";

if ($apuesta['estado'] === 'pendiente' && (float)$saldo_final === 900.00) {
    echo "BUG 1 REPRODUCED SUCCESSFULLY! Bet is stuck in 'pendiente' and $100 is locked indefinitely.\n";
}
