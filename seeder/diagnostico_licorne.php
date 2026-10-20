<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

$contenido = file_get_contents("poblar_imagenes_estadios_wikipedia.php");
if (strpos($contenido, "Stade Crédit Agricole de la Licorne") !== false) {
    echo "MATCH EXACTO: el override esta bien escrito en el archivo.\n";
} else {
    echo "NO HAY MATCH EXACTO. Buscando variantes cercanas...\n";
    if (preg_match("/'Stade[^']*Licorne'/u", $contenido, $m)) {
        echo "Encontrado en el archivo: " . $m[0] . "\n";
        echo "Hex de esa cadena: " . bin2hex($m[0]) . "\n";
    } else {
        echo "No se encontro ninguna linea con 'Licorne' en el archivo.\n";
    }
}
