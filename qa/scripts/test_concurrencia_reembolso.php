<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = db();

// Ensure test match ID 10 exists and is programado
$pdo->exec("UPDATE partidos SET estado = 'programado' WHERE id = 10");

// Get customer qa_cliente
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = 'qa_cliente'");
$stmt->execute();
$cliente = $stmt->fetch();
if (!$cliente) {
    echo "ERROR: Usuario qa_cliente no existe.\n";
    exit(1);
}

$cli_id = (int) $cliente['id'];
$php_bin = 'C:\MAMP\bin\php\php8.3.1\php.exe';

$total_corridas = 50;
$exitos = 0;
$deadlocks = 0;
$dobles_acreditaciones = 0;
$exitos_primer_intento = 0;
$exitos_por_retry = 0;
$log_resultados = [];

echo "===============================================================\n";
echo "INICIANDO SUITE DE ESTRÉS Y CONCURRENCIA REAL (50 ITERACIONES)\n";
echo "Escenario: Admin pospone partido (worker_admin.php CON retry) vs\n";
echo "           Cliente cancela apuesta (worker_cliente.php CON retry)\n";
echo "Versión: v3 - retry en AMBOS lados (ver qa/bugs/BUG-001.md Sección 5)\n";
echo "===============================================================\n\n";

for ($i = 1; $i <= $total_corridas; $i++) {
    // 1. Reset match 10 to 'programado'
    $pdo->exec("UPDATE partidos SET estado = 'programado' WHERE id = 10");

    // 2. Set user balance to 1000.00
    $pdo->prepare("UPDATE usuarios SET saldo = 1000.00 WHERE id = ?")->execute([$cli_id]);

    // 3. Place bet of 50.00 on match 10 (saldo becomes 950.00)
    $monto = 50.00;
    $pdo->prepare("UPDATE usuarios SET saldo = 950.00 WHERE id = ?")->execute([$cli_id]);
    $ref_apuesta = 'APU-CONC-' . $i . '-' . time();
    $pdo->prepare("INSERT INTO apuestas (id_usuario, id_partido, tipo_apuesta, monto, cuota, referencia, estado) VALUES (?, 10, 'local', ?, 2.00, ?, 'pendiente')")
        ->execute([$cli_id, $monto, $ref_apuesta]);
    $apu_id = (int) $pdo->lastInsertId();

    // 4. Launch concurrent processes A and B
    $cmdA = sprintf('"%s" "%s/worker_admin.php" 10', $php_bin, __DIR__);
    $cmdB = sprintf('"%s" "%s/worker_cliente.php" %d %d', $php_bin, __DIR__, $cli_id, $apu_id);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $procA = proc_open($cmdA, $descriptors, $pipesA);
    $procB = proc_open($cmdB, $descriptors, $pipesB);

    $outA = stream_get_contents($pipesA[1]) . stream_get_contents($pipesA[2]);
    $outB = stream_get_contents($pipesB[1]) . stream_get_contents($pipesB[2]);

    fclose($pipesA[1]); fclose($pipesA[2]); proc_close($procA);
    fclose($pipesB[1]); fclose($pipesB[2]); proc_close($procB);

    $outA_trim = trim($outA);
    $outB_trim = trim($outB);

    // 5. Check outputs for deadlocks
    $deadlock_detected = (str_contains($outA, '1213') || str_contains($outB, '1213') || str_contains(strtolower($outA), 'deadlock') || str_contains(strtolower($outB), 'deadlock'));

    if ($deadlock_detected) {
        $deadlocks++;
    }

    // 6. Query DB to verify final state
    $stmt_saldo = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
    $stmt_saldo->execute([$cli_id]);
    $saldo_final = (float) $stmt_saldo->fetchColumn();

    $stmt_tx = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE id_usuario = ? AND (referencia = ? OR descripcion LIKE ?)");
    $stmt_tx->execute([$cli_id, 'REEMB-10-' . $apu_id, '%#' . $apu_id]);
    $cant_tx = (int) $stmt_tx->fetchColumn();

    $doble_pago = ($saldo_final > 1000.00 || $cant_tx > 1);

    if ($doble_pago) {
        $dobles_acreditaciones++;
    }

    // Extraer el número de intento en el que tuvo éxito el lado Admin,
    // para distinguir "éxito directo" de "éxito gracias al retry".
    // El deadlock 1213 siempre mató la transacción del Admin (nunca la del
    // Cliente, ver v1/v2), así que solo el lado Admin necesita esta métrica.
    $admin_intento = null;
    if (preg_match('/ADMIN_SUCCESS \(intento (\d+)\)/', $outA, $m)) {
        $admin_intento = (int) $m[1];
    }

    if (!$deadlock_detected && !$doble_pago && $saldo_final === 1000.00 && $cant_tx === 1) {
        $exitos++;
        $status_run = "OK";

        if ($admin_intento === 1) {
            $exitos_primer_intento++;
        } elseif ($admin_intento !== null && $admin_intento > 1) {
            $exitos_por_retry++;
        }
    } else {
        $status_run = "FALLO";
    }

    $res_summary = sprintf("Corrida %02d/50: Status = %s | Saldo = %.2f | Tx = %d | DL = %s | AdminIntento = %s | Admin: [%s] | Cli: [%s]",
        $i, $status_run, $saldo_final, $cant_tx, $deadlock_detected ? "SI" : "0",
        $admin_intento !== null ? (string) $admin_intento : "?", $outA_trim, $outB_trim
    );
    $log_resultados[] = $res_summary;

    echo $res_summary . "\n";
}

$summary_block = sprintf("

===============================================================
RESUMEN FINAL DE PRUEBA DE CONCURRENCIA v3 (50 CORRIDAS)
Retry en AMBOS lados: worker_admin.php y worker_cliente.php
===============================================================
Total Corridas Ejecutadas: %d
Corridas Exitosas (1 solo reembolso, 0 dobles pagos): %d / %d
  - Exitosas al primer intento (sin deadlock): %d
  - Exitosas SOLO gracias al retry (intento 2 o 3): %d
Errores de Deadlock (InnoDB 1213) sin recuperación: %d
Dobles Acreditaciones / Inconsistencias: %d
===============================================================
", $total_corridas, $exitos, $total_corridas, $exitos_primer_intento, $exitos_por_retry, $deadlocks, $dobles_acreditaciones);

echo $summary_block;

$full_log = implode("\n", $log_resultados) . $summary_block;
file_put_contents(__DIR__ . '/resultados_concurrencia_v3.log', $full_log);

