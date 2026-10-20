<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$id_usuario = (int) ($argv[1] ?? 0);
$id_apuesta = (int) ($argv[2] ?? 0);

if (!$id_usuario || !$id_apuesta) {
    echo "CLIENTE_ERROR: Missing arguments\n";
    exit(1);
}

$maxIntentos = 3;
$pdo = db();

for ($intento = 1; $intento <= $maxIntentos; $intento++) {
    try {
        $pdo->beginTransaction();

        // Lock 1: Lock usuarios primero
        $stmt_u = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
        $stmt_u->execute([$id_usuario]);
        $saldo_anterior = $stmt_u->fetchColumn();

        if ($saldo_anterior === false) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        $saldo_anterior = (float) $saldo_anterior;

        // Lock 2: Lock apuestas segundo (SIN JOIN partidos)
        $stmt_a = $pdo->prepare('SELECT * FROM apuestas WHERE id = ? AND id_usuario = ? FOR UPDATE');
        $stmt_a->execute([$id_apuesta, $id_usuario]);
        $apuesta = $stmt_a->fetch();

        if (!$apuesta) {
            throw new RuntimeException('Apuesta no encontrada.');
        }

        // Lectura informativa SIN LOCK sobre partidos
        $stmt_p = $pdo->prepare('SELECT estado FROM partidos WHERE id = ?');
        $stmt_p->execute([$apuesta['id_partido']]);
        $estado_partido = $stmt_p->fetchColumn();

        if ($apuesta['estado'] !== 'pendiente' || $estado_partido !== 'programado') {
            throw new RuntimeException('Apuesta ya no cancelable');
        }

        $monto = (float) $apuesta['monto'];
        $saldo_nuevo = $saldo_anterior + $monto;

        $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')->execute([$saldo_nuevo, $id_usuario]);
        $pdo->prepare('UPDATE apuestas SET estado = "cancelada", resuelta_at = NOW() WHERE id = ? AND estado = "pendiente"')
            ->execute([$id_apuesta]);

        $ref_tx = generar_referencia('REEMB-CLI');
        $pdo->prepare(
            'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
             VALUES (?, "reembolso_cancelacion_cliente", ?, ?, ?, ?, ?)'
        )->execute([
            $id_usuario, $monto, $saldo_anterior, $saldo_nuevo, $ref_tx,
            'Cancelación manual test concurrencia #' . $id_apuesta,
        ]);

        $pdo->commit();
        echo "CLIENTE_SUCCESS\n";
        exit(0);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $esDeadlock = ($e instanceof PDOException && (string)$e->getCode() === '40001');
        if ($esDeadlock && $intento < $maxIntentos) {
            usleep(random_int(50000, 150000)); // backoff aleatorio 50-150ms
            continue;
        }

        echo "CLIENTE_REJECTED: " . $e->getMessage() . "\n";
        exit(0);
    }
}
