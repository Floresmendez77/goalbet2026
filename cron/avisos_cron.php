<?php

/**
 * Avisos por correo (favoritos, alineaciones, resultados de apuestas, boletos, reembolsos).
 *
 * Programar CADA 15 MINUTOS (AlwaysData: Tareas programadas):
 *   php /ruta/al/proyecto/cron/avisos_cron.php
 * o por URL:  https://tusitio/cron/avisos_cron.php?key=CRON_SECRET_KEY
 *
 * Los resultados salen de la sincronización (cron/sync_cron.php): mientras ese cron no
 * actualice el marcador del partido, el aviso de resultado no se dispara.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/avisos.php';

$cronKey = trim((string) Env::get('CRON_SECRET_KEY', ''));
if ($cronKey === '') {
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
    }
    fwrite(STDERR, "Error de configuración: CRON_SECRET_KEY no está definida en el .env. Abortando.\n");
    exit(1);
}
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals($cronKey, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo "Acceso denegado: clave secreta inválida.\n";
        exit(1);
    }
}

set_time_limit(280);
echo '[' . date('Y-m-d H:i:s') . "] AVISOS POR CORREO...\n";

$pdo = db();
if (!avisos_tablas_listas($pdo)) {
    echo "Faltan tablas/columnas: importá database/migracion_fase1b_avisos.sql\n";
    exit(1);
}
if (!mail_configurado() && Env::get('APP_ENV', 'production') !== 'local') {
    echo "SMTP no configurado (MAIL_HOST/MAIL_USER/MAIL_PASS). No se envía nada.\n";
    exit(1);
}

$r = avisos_ejecutar($pdo, 80);
foreach ($r as $k => $v) {
    echo str_pad($k, 24) . $v . "\n";
}
if ($r['pendientes_por_limite']) {
    echo "Se llegó al límite de 80 correos por corrida; el resto sale en la próxima.\n";
}
echo '[' . date('Y-m-d H:i:s') . "] LISTO.\n";
