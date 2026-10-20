<?php

/**
 * Script CLI de Sincronización Programada (Cron).
 *
 * Ejecución recomendada en crontab / programador de tareas:
 * cada 1 hora o cada 30 minutos:
 *   * /30 * * * * /usr/bin/php /ruta/al/proyecto/cron/sync_cron.php >> /ruta/al/proyecto/seeder/logs/cron.log 2>&1
 */

declare(strict_types=1);

// Exigir que CRON_SECRET_KEY esté definida siempre (abortar si no lo está)
require_once __DIR__ . '/../includes/bootstrap.php';

$cronKey = trim((string) Env::get('CRON_SECRET_KEY', ''));
if ($cronKey === '') {
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
    }
    fwrite(STDERR, "Error de configuración: CRON_SECRET_KEY no está definida en el entorno (.env). Abortando.\n");
    exit(1);
}

// Permitir ejecución por CLI, o por GET si se pasa la clave secreta configurada
if (php_sapi_name() !== 'cli') {
    $token = (string) ($_GET['key'] ?? '');
    if (!hash_equals($cronKey, $token)) {
        http_response_code(403);
        echo "Acceso denegado: clave secreta inválida.\n";
        exit(1);
    }
}

require_once __DIR__ . '/../includes/Sincronizador.php';

$diasAtras = 7;
$diasAdelante = 14;

// Opciones opcionales por CLI
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--dias-atras=')) {
            $diasAtras = (int) substr($arg, strlen('--dias-atras='));
        }
        if (str_starts_with($arg, '--dias-adelante=')) {
            $diasAdelante = (int) substr($arg, strlen('--dias-adelante='));
        }
    }
}

$horaInicio = date('Y-m-d H:i:s');
echo "[$horaInicio] INICIANDO SINCRONIZACIÓN AUTOMÁTICA GOALBET2026...\n";
echo "Rango de fechas: -$diasAtras días hasta +$diasAdelante días.\n";

$sincronizador = new Sincronizador();
$resumen = $sincronizador->sincronizarTodas($diasAtras, $diasAdelante, 'Cron-CLI');

echo "\n--- RESUMEN DE EJECUCIÓN ---\n";
echo "Total ligas procesadas: {$resumen['total_ligas']}\n";
echo "Resultados actualizados: {$resumen['total_actualizados']}\n";
echo "Partidos nuevos creados: {$resumen['total_creados']}\n";
echo "Errores: {$resumen['total_errores']}\n";

foreach ($resumen['detalle_por_liga'] as $d) {
    $estado = $d['exito'] ? 'OK' : 'ERROR';
    echo "  - [{$estado}] {$d['liga']}: {$d['actualizados']} actualizados, {$d['creados']} creados.\n";
}

$horaFin = date('Y-m-d H:i:s');
echo "[$horaFin] SINCRONIZACIÓN FINALIZADA CON ÉXITO.\n";
