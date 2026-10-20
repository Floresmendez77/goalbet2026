<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$id_partido = (int) ($argv[1] ?? 0);
if (!$id_partido) {
    echo "ADMIN_ERROR: Missing id_partido\n";
    exit(1);
}

$maxIntentos = 3;
$pdo = db();

for ($intento = 1; $intento <= $maxIntentos; $intento++) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE partidos SET estado = 'pospuesto' WHERE id = ?");
        $stmt->execute([$id_partido]);

        $pdo->commit();
        echo "ADMIN_SUCCESS (intento $intento)\n";
        exit(0);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $esDeadlock = ($e instanceof PDOException && (string) $e->getCode() === '40001');
        if ($esDeadlock && $intento < $maxIntentos) {
            usleep(random_int(50000, 150000)); // backoff aleatorio 50-150ms
            continue;
        }

        echo "ADMIN_ERROR (agotados $intento intentos): " . $e->getMessage() . "\n";
        exit(0);
    }
}
