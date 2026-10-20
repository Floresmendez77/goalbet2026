<?php

/**
 * diagnostico_mlb.php — SOLO LECTURA, no modifica nada.
 * Imprime:
 *  1. Los 34 nombres+id exactos que devuelve la API de baseball.
 *  2. Los equipos locales de MLB con su external_id actual (si tienen).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}

$apiKey = Env::get('API_SPORTS_KEY', '');

$ch = curl_init('https://v1.baseball.api-sports.io/teams?league=1&season=2023');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["x-apisports-key: $apiKey"],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NO_REVOKE,
]);
$body = curl_exec($ch);
curl_close($ch);
$data = json_decode($body, true);

echo "== Equipos segun la API (id -> nombre) ==\n";
foreach ($data['response'] ?? [] as $eq) {
    echo "  {$eq['id']} -> {$eq['name']}\n";
}

echo "\n== Equipos locales de MLB (id_liga=19) con su external_id ==\n";
$sql = "
    SELECT e.id, e.nombre, e.activo, ext.external_id
    FROM equipos e
    LEFT JOIN external_ids ext
        ON ext.entidad = 'equipo' AND ext.entidad_id = e.id AND ext.fuente = 'api-sports'
    WHERE e.id_deporte = 3 AND e.id_liga = 19
    ORDER BY e.nombre
";
foreach (db()->query($sql)->fetchAll() as $row) {
    $activo = $row['activo'] ? 'activo' : 'INACTIVO';
    $ext = $row['external_id'] ?? '(sin external_id)';
    echo "  id={$row['id']} '{$row['nombre']}' [$activo] external_id=$ext\n";
}