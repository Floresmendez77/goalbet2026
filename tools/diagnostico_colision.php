<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}

$sql = "
    SELECT ei.external_id, ei.fuente, e.id AS id_equipo, e.nombre, d.nombre AS deporte, lt.nombre AS liga
    FROM external_ids ei
    JOIN equipos e ON e.id = ei.entidad_id
    JOIN deportes d ON d.id = e.id_deporte
    LEFT JOIN ligas_torneos lt ON lt.id = e.id_liga
    WHERE ei.fuente = 'api-sports' AND ei.entidad = 'equipo'
      AND ei.external_id IN ('33','34','35','36')
    ORDER BY ei.external_id
";
foreach (db()->query($sql)->fetchAll() as $row) {
    echo "external_id={$row['external_id']} -> '{$row['nombre']}' ({$row['deporte']} / {$row['liga']}, equipo id={$row['id_equipo']})\n";
}