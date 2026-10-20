<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Script one-off: recalcula tabla_posiciones SOLO para Champions League
 * (id interno de liga 26), usando la version corregida de
 * Repository::recalcularTablaPosiciones() que ahora detecta torneos de
 * fase de grupos (fase LIKE "Group%") y excluye clasificatorias,
 * playoffs previos y toda la eliminatoria directa (Round of 16 en
 * adelante), que no tienen tabla de posiciones real.
 *
 * No vuelve a pegarle a ninguna API externa, no crea ni modifica
 * partidos ni equipos. Solo relee `partidos` y reescribe
 * `tabla_posiciones` para la liga 26.
 *
 * Correr UNA vez, desde la carpeta seeder/, despues de reemplazar
 * Repository.php, con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" recalcular_tabla_champions.php
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

$pdo = Database::connect();
$logger = new Logger();
$repo = new Repository($pdo, $logger);

// ID interno (columna `id` de ligas_torneos) de Champions League.
$idLiga = 26;
$temporada = '2023';

echo "Recalculando liga $idLiga (Champions League, temporada $temporada)...\n";
$repo->recalcularTablaPosiciones($idLiga, $temporada);

echo "Listo.\n";