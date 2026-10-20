<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// Busca el ID correcto de la NFL en v1.american-football.api-sports.io
// Version debug: muestra la respuesta cruda completa.
// Correr desde la carpeta seeder/ con: php buscar_nfl.php

require __DIR__ . '/Env.php';
Env::load(__DIR__ . '/.env');

$key = Env::get('API_SPORTS_KEY', '');
echo "Usando key: " . (strlen($key) > 4 ? substr($key, 0, 4) . '...' . substr($key, -4) : '(VACIA)') . PHP_EOL . PHP_EOL;

$ch = curl_init('https://v1.american-football.api-sports.io/leagues');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["x-apisports-key: $key"],
    CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NO_REVOKE,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    echo 'ERROR cURL: ' . curl_error($ch) . PHP_EOL;
    exit(1);
}
curl_close($ch);

echo "HTTP status: $status" . PHP_EOL;
echo "Respuesta cruda completa:" . PHP_EOL;
echo $body . PHP_EOL . PHP_EOL;

$data = json_decode($body, true);
echo "Total encontradas: " . ($data['results'] ?? 0) . PHP_EOL;
foreach ($data['response'] ?? [] as $liga) {
    $id = $liga['id'] ?? '?';
    $nombre = $liga['name'] ?? '?';
    $tipo = $liga['type'] ?? '?';
    echo " - id=$id | $nombre ($tipo)" . PHP_EOL;
}