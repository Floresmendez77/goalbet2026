<?php

/**
 * Cron en vivo (tiempo real) — GoalBet2026.
 *
 * Ejecutar CADA MINUTO. Si no hay partidos de fútbol activos, sale de inmediato
 * sin hacer ninguna llamada HTTP a ESPN.
 *
 * Partido activo = estado 'en_curso', o 'programado' cuyo inicio esté entre
 * -4 horas y +15 minutos respecto a ahora. fecha_partido se guarda en UTC
 * (EspnSeeder escribe la fecha UTC del evento), así que todo se compara en UTC.
 *
 * Por cada liga de fútbol con partidos activos:
 *   1) UNA sincronización de la liga (solo ayer y hoy, sin crear partidos nuevos)
 *      -> 2 llamadas HTTP al scoreboard de ESPN (una por día).
 *   2) Por cada partido activo, DetallePartido::importar() -> 1 llamada al summary.
 *
 * Linux (crontab):
 *   * * * * * /usr/bin/php /ruta/al/proyecto/cron/live_cron.php >> /ruta/al/proyecto/seeder/logs/live_cron.out 2>&1
 *
 * Opciones CLI: --verbose  (imprime también cuando no hay nada que hacer)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$esCli = php_sapi_name() === 'cli';
$verbose = $esCli && in_array('--verbose', $argv ?? [], true);

// --- Control de acceso: igual que sync_cron.php (CLI o clave secreta) ---------
$cronKey = trim((string) Env::get('CRON_SECRET_KEY', ''));
if ($cronKey === '') {
    if (!$esCli) {
        http_response_code(500);
    }
    fwrite(STDERR, "Error de configuración: CRON_SECRET_KEY no está definida en el entorno (.env). Abortando.\n");
    exit(1);
}
if (!$esCli) {
    $token = (string) ($_GET['key'] ?? '');
    if (!hash_equals($cronKey, $token)) {
        http_response_code(403);
        echo "Acceso denegado: clave secreta inválida.\n";
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(120);
}

// --- Log propio (seeder/logs está bloqueado por .htaccess y en .gitignore) ----
$logDir = SEEDER_PATH . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$escribirLog = static function (string $nivel, string $msg) use ($logDir, $esCli): void {
    $linea = sprintf('[%s UTC] %-5s %s', gmdate('Y-m-d H:i:s'), $nivel, $msg);
    @file_put_contents($logDir . '/live_cron.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
    if (!$esCli) {
        echo $linea . PHP_EOL;
    }
};

// --- Lock: que dos ejecuciones no se solapen -----------------------------------
$lock = @fopen($logDir . '/live_cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $escribirLog('WARN', 'Ejecución anterior todavía en curso; se omite esta.');
    exit(0);
}

try {
    $pdo = db();

    // --- Partidos activos (ventana en UTC) -------------------------------------
    $desdeUtc = gmdate('Y-m-d H:i:s', time() - 4 * 3600);
    $hastaUtc = gmdate('Y-m-d H:i:s', time() + 15 * 60);

    $stmt = $pdo->prepare(
        "SELECT p.id, p.id_liga, p.estado
         FROM partidos p
         JOIN ligas_sync ls ON ls.id_liga = p.id_liga AND ls.deporte = 'futbol' AND ls.activa = 1
         WHERE p.estado = 'en_curso'
            OR (p.estado = 'programado' AND p.fecha_partido BETWEEN :desde AND :hasta)
         ORDER BY p.id_liga, p.id"
    );
    $stmt->execute([':desde' => $desdeUtc, ':hasta' => $hastaUtc]);
    $activos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Salida inmediata: sin partidos activos no se toca ESPN ni se carga el sincronizador.
    if ($activos === []) {
        if ($verbose) {
            echo "[" . gmdate('Y-m-d H:i:s') . " UTC] Sin partidos activos. Nada que hacer.\n";
        }
        exit(0);
    }

    require_once __DIR__ . '/../includes/Sincronizador.php';
    require_once __DIR__ . '/../includes/DetallePartido.php';

    $porLiga = [];
    foreach ($activos as $a) {
        $porLiga[(int) $a['id_liga']][] = (int) $a['id'];
    }

    $escribirLog('INFO', sprintf(
        'Inicio: %d partido(s) activo(s) en %d liga(s).',
        count($activos),
        count($porLiga)
    ));

    $ayer = gmdate('Y-m-d', time() - 86400);
    $hoy = gmdate('Y-m-d');
    $totalErrores = 0;

    foreach ($porLiga as $idLiga => $idsPartidos) {
        // 1) UNA sincronización por liga: ayer y hoy, sin crear partidos nuevos.
        //    Instancia nueva por liga: EspnSeeder acumula contadores por instancia.
        //    La salida por echo del Logger del seeder se captura (ya queda en seeder/logs/seed_*.log).
        ob_start();
        try {
            $res = (new Sincronizador($pdo))->sincronizarLiga($idLiga, $ayer, $hoy, 'Cron-Vivo', false);
        } catch (Throwable $e) {
            $res = ['exito' => false, 'error' => $e->getMessage()];
        }
        ob_end_clean();

        if (empty($res['exito'])) {
            $totalErrores++;
            $escribirLog('ERROR', "Liga #$idLiga: " . ($res['error'] ?? $res['detalle'] ?? 'fallo desconocido'));
        } else {
            $escribirLog('INFO', sprintf(
                'Liga #%d (%s): %d actualizados, %d omitidos, %d sin match.',
                $idLiga,
                $res['liga'] ?? '?',
                (int) ($res['actualizados'] ?? 0),
                (int) ($res['omitidos'] ?? 0),
                (int) ($res['sin_match'] ?? 0)
            ));
        }

        // 2) Eventos (goles, asistencias, tarjetas, cambios) de cada partido activo.
        foreach ($idsPartidos as $idPartido) {
            $r = DetallePartido::importar($pdo, $idPartido, 1);
            if ($r['ok']) {
                $escribirLog('INFO', "Partido #$idPartido: " . $r['mensaje']);
            } else {
                $escribirLog('WARN', "Partido #$idPartido: " . $r['mensaje']);
            }
        }
    }

    $escribirLog('INFO', "Fin. Errores de sincronización: $totalErrores.");
    exit($totalErrores > 0 ? 2 : 0);
} catch (Throwable $e) {
    $escribirLog('ERROR', 'Excepción no controlada: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}