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
curl_close($ch);

$data = json_decode($body, true);
$arr = $data['countries'] ?? $data['leagues'] ?? [];

echo "Total ligas de basquet encontradas: " . count($arr) . "\n\n";
echo "Ligas que contienen 'NBA' o 'National':\n";
foreach ($arr as $l) {
    $nombre = $l['strLeague'] ?? '';
    if (stripos($nombre, 'NBA') !== false || stripos($nombre, 'National') !== false) {
        echo " - '$nombre'\n";
    }
}

echo "\nTodas las ligas encontradas (primeras 30):\n";
$i = 0;
foreach ($arr as $l) {
    if ($i >= 30) break;
    echo " - '" . ($l['strLeague'] ?? '?') . "' (pais: " . ($l['strCountry'] ?? '?') . ")\n";
    $i++;
}