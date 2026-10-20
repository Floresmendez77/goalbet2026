<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Script one-off: vuelve a calcular tabla_posiciones para las ligas de
 * futbol ya sembradas, usando la version corregida de
 * Repository::recalcularTablaPosiciones() (que ahora excluye partidos
 * de repechaje/promocion-descenso con jornada IS NULL).
 *
 * No vuelve a pegarle a ninguna API externa, no crea ni modifica
 * partidos ni equipos. Solo relee `partidos` y reescribe
 * `tabla_posiciones` para las ligas listadas abajo.
 *
 * Correr UNA vez, desde la carpeta seeder/, despues de reemplazar
 * Repository.php, con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" recalcular_tablas_futbol.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

$pdo = Database::connect();
$logger = new Logger();
$repo = new Repository($pdo, $logger);

// IDs internos (columna `id` de ligas_torneos) de las ligas de futbol
// ya sembradas con api-sports que hay que recalcular.
$ligas = [
    15 => '2023', // Premier League
    16 => '2023', // La Liga
    17 => '2023', // Serie A
    21 => '2023', // Bundesliga
    22 => '2023', // Ligue 1
];

foreach ($ligas as $idLiga => $temporada) {
    echo "Recalculando liga $idLiga (temporada $temporada)...\n";
    $repo->recalcularTablaPosiciones($idLiga, $temporada);
}

echo "Listo.\n";