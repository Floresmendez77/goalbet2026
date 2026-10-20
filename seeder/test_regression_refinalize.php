<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();

// Setup match 2 (Futbol: Hull City vs Manchester United)
$pdo->exec("UPDATE partidos SET estado = 'programado', marcador_local = NULL, marcador_visita = NULL WHERE id = 2");
$pdo->exec("DELETE FROM apuestas WHERE id_partido = 2");

// Get customer qa_cliente
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = 'qa_cliente'");
$stmt->execute();
$cliente = $stmt->fetch();

$saldo_inicio = (float)$cliente['saldo'];
echo "Saldo inicial cliente: $saldo_inicio\n";

// Cliente bets 100 on 'local' (Hull City) at odds 2.00
$monto = 100.00;
$cuota = 2.00;
$ganancia_potencial = 200.00;
$saldo_post_apuesta = $saldo_inicio - $monto;

$pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?")->execute([$saldo_post_apuesta, $cliente['id']]);
$pdo->prepare("INSERT INTO apuestas (id_usuario, id_partido, tipo_apuesta, monto, cuota, referencia, estado) VALUES (?, 2, 'local', ?, ?, ?, 'pendiente')")
    ->execute([$cliente['id'], $monto, $cuota, 'APU-REG-01']);
$id_apuesta = $pdo->lastInsertId();

echo "Apuesta #$id_apuesta creada. Saldo cliente tras apostar: $saldo_post_apuesta\n";

// First finalization: Admin sets match 2 result (2 - 1) -> Local wins!
$pdo->prepare("UPDATE partidos SET marcador_local = 2, marcador_visita = 1, estado = 'finalizado' WHERE id = 2")->execute();

// Check balance & transactions after 1st finalization
$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$cliente['id']]);
$saldo_post_pago1 = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE id_usuario = ? AND tipo = 'pago_ganancia' AND referencia LIKE ?");
$stmt->execute([$cliente['id'], "PAGO-2-%"]);
$tx_count1 = (int)$stmt->fetchColumn();

echo "--- PRIMERA FINALIZACIÓN ---\n";
echo "Saldo cliente tras 1er pago: $saldo_post_pago1 (Esperado: " . ($saldo_post_apuesta + 200.00) . ")\n";
echo "Transacciones de pago registradas: $tx_count1\n";

// Second finalization: Admin updates match 2 result again (same 2 - 1)
$pdo->prepare("UPDATE partidos SET marcador_local = 2, marcador_visita = 1, estado = 'finalizado' WHERE id = 2")->execute();

$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$cliente['id']]);
$saldo_post_pago2 = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE id_usuario = ? AND tipo = 'pago_ganancia' AND referencia LIKE ?");
$stmt->execute([$cliente['id'], "PAGO-2-%"]);
$tx_count2 = (int)$stmt->fetchColumn();

echo "--- SEGUNDA FINALIZACIÓN (REGRESIÓN) ---\n";
echo "Saldo cliente tras 2do guardado: $saldo_post_pago2\n";
echo "Transacciones de pago registradas: $tx_count2\n";

if ($saldo_post_pago1 === $saldo_post_pago2 && $tx_count1 === 1 && $tx_count2 === 1) {
    echo "PRUEBA DE REGRESIÓN EXITOSA: Re-guardar el resultado no duplicó el pago ni alteró el saldo.\n";
} else {
    echo "FALLO EN REGRESIÓN: Se detectó pago duplicado.\n";
}
