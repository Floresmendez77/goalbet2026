<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

$url = 'https://www.thesportsdb.com/api/v1/json/3/search_all_leagues.php?s=Basketball';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "STATUS: $status\n\n";
echo "RESPUESTA CRUDA:\n";
echo $body . "\n\n";

$data = json_decode($body, true);
if (isset($data['countrys'])) {
    echo "Ligas encontradas (countrys):\n";
    foreach ($data['countrys'] as $l) {
        echo " - " . ($l['strLeague'] ?? '?') . "\n";
    }
} else {
    echo "No hay clave 'countrys' en la respuesta.\n";
}