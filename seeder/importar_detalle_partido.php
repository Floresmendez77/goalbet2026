<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/importar_detalle_partido.php
//
// Importa desde ESPN el detalle (estadio, alineaciones, cronologia, estadisticas)
// de partidos de futbol. Pensado para correr a mano o desde el cron.
//
// Uso (desde la carpeta seeder):
//   php importar_detalle_partido.php --partido=9159            un partido
//   php importar_detalle_partido.php --partido=9159 --forzar   reimportar aunque ya tenga datos
//   php importar_detalle_partido.php --dias=7                  finalizados de los ultimos 7 dias que aun no tienen detalle
//   php importar_detalle_partido.php --liga=9 --dias=30 --limite=60
//
// Opciones: --liga=ID_LIGA (interno)  --dias=N (default 7)  --limite=N (default 40)  --forzar

declare(strict_types=1);

// Mostrar siempre los errores (el php.ini de MAMP puede ocultarlos y el script
// terminaria en silencio).
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nERROR FATAL: {$e['message']} en {$e['file']}:{$e['line']}\n";
    }
});

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DetallePartido.php';

$opt = ['partido' => null, 'liga' => null, 'dias' => 7, 'limite' => 40, 'forzar' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--forzar') { $opt['forzar'] = true; continue; }
    if (preg_match('/^--(partido|liga|dias|limite)=(\d+)$/', $a, $m)) { $opt[$m[1]] = (int) $m[2]; continue; }
    echo "Opcion no reconocida: $a\n";
    exit(1);
}

$pdo = db();
if (!DetallePartido::tablasListas($pdo)) {
    echo "Faltan las tablas. Ejecuta database/detalle_partido.sql en phpMyAdmin primero.\n";
    exit(1);
}

if ($opt['partido']) {
    $ids = [(int) $opt['partido']];
} else {
    $sql = "SELECT p.id
            FROM partidos p
            JOIN external_ids ei ON ei.entidad = 'partido' AND ei.entidad_id = p.id AND ei.fuente = 'espn-futbol'
            JOIN ligas_sync ls ON ls.id_liga = p.id_liga AND ls.deporte = 'futbol'
            LEFT JOIN partido_detalle pd ON pd.id_partido = p.id
            WHERE p.estado = 'finalizado'
              AND p.fecha_partido >= (UTC_TIMESTAMP() - INTERVAL :dias DAY)";
    $params = [':dias' => $opt['dias']];
    if (!$opt['forzar']) {
        $sql .= ' AND (pd.id_partido IS NULL OR pd.tiene_datos = 0)';
    }
    if ($opt['liga']) {
        $sql .= ' AND p.id_liga = :liga';
        $params[':liga'] = $opt['liga'];
    }
    $sql .= ' ORDER BY p.fecha_partido DESC LIMIT ' . (int) $opt['limite'];
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

echo count($ids) . " partido(s) a importar.\n";
$ok = $fallo = 0;
foreach ($ids as $i => $id) {
    try {
        $r = DetallePartido::importar($pdo, $id, 3);
    } catch (Throwable $ex) {
        $r = ['ok' => false, 'mensaje' => 'EXCEPCION: ' . $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')'];
    }
    printf("[%d/%d] partido #%d: %s %s\n", $i + 1, count($ids), $id, $r['ok'] ? 'OK' : 'FALLO', $r['mensaje']);
    $r['ok'] ? $ok++ : $fallo++;
    if ($i < count($ids) - 1) {
        sleep(1); // no saturar a ESPN
    }
}
echo "Listo: $ok importados, $fallo con problemas.\n";