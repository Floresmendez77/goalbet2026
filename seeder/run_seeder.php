<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Seeders/FootballDataSeeder.php';
require __DIR__ . '/Seeders/ApiSportsSeeder.php';
require __DIR__ . '/Seeders/TheSportsDbSeeder.php';

Env::load(__DIR__ . '/.env');

// ------------------------------------------------------------------
// Parseo de argumentos: --fuente=x --liga=y --temporada=z --deporte=w
// ------------------------------------------------------------------
$args = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $args[$k] = $v;
    }
}

$fuente = $args['fuente'] ?? null;
if ($fuente === null) {
    fwrite(STDERR, "Uso: php run_seeder.php --fuente=<football-data|api-sports|thesportsdb> [opciones]\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

try {
    switch ($fuente) {
        case 'football-data':
            $liga = $args['liga'] ?? 'PL'; // codigo ISO de la competicion, ej PL, PD, SA
            $seeder = new FootballDataSeeder($repo, $http, $logger, Env::get('FOOTBALL_DATA_API_KEY', ''));
            $seeder->run($liga, $args['temporada'] ?? null);
            break;

        case 'api-sports':
            $deporte = $args['deporte'] ?? 'baloncesto'; // baloncesto|beisbol|futbol-americano
           $dominios = [
                  'baloncesto'       => 'v1.basketball.api-sports.io',
                   'beisbol'          => 'v1.baseball.api-sports.io',
                 'futbol-americano' => 'v1.american-football.api-sports.io',
                'futbol'           => 'v3.football.api-sports.io',
];
            if (!isset($dominios[$deporte])) {
                throw new InvalidArgumentException("--deporte debe ser: " . implode('|', array_keys($dominios)));
            }
            $seeder = new ApiSportsSeeder(
                $repo,
                $http,
                $logger,
                Env::get('API_SPORTS_KEY', ''),
                $dominios[$deporte],
                $deporte
            );
            $seeder->run((int)($args['liga'] ?? 0), $args['temporada'] ?? (string)date('Y'));
            break;

        case 'thesportsdb':
            $deporte = $args['deporte'] ?? 'baloncesto';
            $liga = $args['liga'] ?? 'NBA';
            $seeder = new TheSportsDbSeeder($repo, $http, $logger);
            $seeder->run($liga, $deporte);
            break;

        default:
            throw new InvalidArgumentException("Fuente desconocida: $fuente");
    }
} catch (Throwable $e) {
    $logger->error('Fallo fatal: ' . $e->getMessage());
}

$logger->resumen();
